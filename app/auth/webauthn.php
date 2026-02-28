<?php
/**
 * WebAuthn ceremony endpoint — JSON API for passkey registration and authentication.
 *
 * Actions (POST JSON body: {"action": "...", ...}):
 *   reg_challenge   — generate a registration challenge for the current user
 *   reg_verify      — verify and store a new passkey credential
 *   auth_challenge  — generate an authentication challenge (login)
 *   auth_verify     — verify authentication assertion and create session
 */

ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticationExtensions\AuthenticationExtensionsClientInputs;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Cose\Algorithm\Manager as CoseAlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\ECDSA\ES384;
use Cose\Algorithm\Signature\ECDSA\ES512;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Algorithm\Signature\RSA\RS384;
use Cose\Algorithm\Signature\RSA\RS512;
use Cose\Algorithm\Signature\EdDSA\Ed256;
use Webauthn\PublicKeyCredentialSource;

// ── Helpers ──────────────────────────────────────────────────────────────────

function getOrigin(): string
{
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function getRpId(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Strip port number
    return explode(':', $host)[0];
}

function getRp(): PublicKeyCredentialRpEntity
{
    return PublicKeyCredentialRpEntity::create(
        name: 'StormPath',
        id:   getRpId(),
    );
}

function buildCoseAlgorithmManager(): CoseAlgorithmManager
{
    $manager = new CoseAlgorithmManager();
    $manager->add(new ES256());
    $manager->add(new ES384());
    $manager->add(new ES512());
    $manager->add(new RS256());
    $manager->add(new RS384());
    $manager->add(new RS512());
    $manager->add(new Ed256());
    return $manager;
}

function getSerializer()
{
    // AttestationStatementSupportManager::create() already adds NoneAttestationStatementSupport
    // by default. Do NOT chain ->add() here — that method returns void, which would pass
    // null to WebauthnSerializerFactory and cause a fatal error.
    $factory = new WebauthnSerializerFactory(
        AttestationStatementSupportManager::create()
    );
    return $factory->create();
}

function storeChallenge(string $id, string $challenge, ?int $userId, string $type): void
{
    cleanupExpiredChallenges();
    $db = getDb();
    $db->prepare(
        "INSERT OR REPLACE INTO webauthn_challenges (id, challenge, user_id, type) VALUES (?, ?, ?, ?)"
    )->execute([$id, base64_encode($challenge), $userId, $type]);
}

function fetchChallenge(string $id, string $type): ?array
{
    cleanupExpiredChallenges();
    $db   = getDb();
    $stmt = $db->prepare("SELECT * FROM webauthn_challenges WHERE id = ? AND type = ?");
    $stmt->execute([$id, $type]);
    $row  = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $db->prepare("DELETE FROM webauthn_challenges WHERE id = ?")->execute([$id]);
    return $row;
}

// ── Request parsing ───────────────────────────────────────────────────────────

$input    = file_get_contents('php://input');
$postData = json_decode($input, true);
$action   = $postData['action'] ?? null;

if (!$action) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No action specified']);
    exit;
}

try {
    $serializer = getSerializer();

    switch ($action) {

        // ── Registration challenge ─────────────────────────────────────────
        case 'reg_challenge': {
            $user = requireAuth();

            $challenge    = random_bytes(32);
            $challengeId  = bin2hex(random_bytes(8));
            $userEntity   = PublicKeyCredentialUserEntity::create(
                name:        $user['username'],
                id:          (string) $user['id'],
                displayName: $user['display_name'] ?? $user['username'],
            );

            $options = PublicKeyCredentialCreationOptions::create(
                rp:                      getRp(),
                user:                    $userEntity,
                challenge:               $challenge,
                pubKeyCredParams:        [
                    PublicKeyCredentialParameters::create('public-key', -7),   // ES256
                    PublicKeyCredentialParameters::create('public-key', -257), // RS256
                    PublicKeyCredentialParameters::create('public-key', -8),   // Ed25519
                ],
                authenticatorSelection:  AuthenticatorSelectionCriteria::create(
                    residentKey:          AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
                    userVerification:     AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                ),
                attestation:             PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
                timeout:                 60000,
            );

            storeChallenge($challengeId, $challenge, (int)$user['id'], 'registration');

            $optionsArray          = json_decode($serializer->serialize($options, 'json'), true);
            $optionsArray['_cid']  = $challengeId; // pass back to client so we can look it up on verify
            echo json_encode(['success' => true, 'options' => $optionsArray]);
            break;
        }

        // ── Registration verify ────────────────────────────────────────────
        case 'reg_verify': {
            $user        = requireAuth();
            $challengeId = $postData['challengeId'] ?? '';
            $credJson    = $postData['credential']  ?? null;
            $passkeyName = trim($postData['name']   ?? 'Passkey');

            if (!$challengeId || !$credJson) {
                throw new RuntimeException('Missing challengeId or credential');
            }

            $row = fetchChallenge($challengeId, 'registration');
            if (!$row) {
                throw new RuntimeException('Challenge not found or expired');
            }

            $challenge = base64_decode($row['challenge']);

            $credential = $serializer->deserialize(
                json_encode($credJson),
                PublicKeyCredential::class,
                'json'
            );

            if (!$credential->response instanceof AuthenticatorAttestationResponse) {
                throw new RuntimeException('Invalid response type');
            }

            $userEntity = PublicKeyCredentialUserEntity::create(
                name:        $user['username'],
                id:          (string) $user['id'],
                displayName: $user['display_name'] ?? $user['username'],
            );

            $creationOptions = PublicKeyCredentialCreationOptions::create(
                rp:              getRp(),
                user:            $userEntity,
                challenge:       $challenge,
                pubKeyCredParams: [
                    PublicKeyCredentialParameters::create('public-key', -7),
                    PublicKeyCredentialParameters::create('public-key', -257),
                    PublicKeyCredentialParameters::create('public-key', -8),
                ],
            );

            $csmFactory = new CeremonyStepManagerFactory();
            $csmFactory->setAlgorithmManager(buildCoseAlgorithmManager());
            $csm = $csmFactory->creationCeremony();

            // Use named argument — first positional arg is AttestationStatementSupportManager, not CeremonyStepManager
            $validator = AuthenticatorAttestationResponseValidator::create(ceremonyStepManager: $csm);
            $source    = $validator->check(
                authenticatorAttestationResponse: $credential->response,
                publicKeyCredentialCreationOptions: $creationOptions,
                request: getOrigin(),
            );

            // Persist the credential
            $credId  = base64_encode($source->publicKeyCredentialId);
            $pubKey  = base64_encode($serializer->serialize($source, 'json'));
            $aaguid  = (string) $source->aaguid;

            getDb()->prepare(
                "INSERT INTO passkeys (user_id, credential_id, public_key_cbor, sign_count, aaguid, name)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([(int)$user['id'], $credId, $pubKey, $source->counter, $aaguid, $passkeyName]);

            echo json_encode(['success' => true]);
            break;
        }

        // ── Authentication challenge ───────────────────────────────────────
        case 'auth_challenge': {
            $challenge   = random_bytes(32);
            $challengeId = bin2hex(random_bytes(8));

            $options = PublicKeyCredentialRequestOptions::create(
                challenge:        $challenge,
                rpId:             getRpId(),
                userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                timeout:          60000,
            );

            storeChallenge($challengeId, $challenge, null, 'authentication');

            $optionsArray         = json_decode($serializer->serialize($options, 'json'), true);
            $optionsArray['_cid'] = $challengeId;
            echo json_encode(['success' => true, 'options' => $optionsArray]);
            break;
        }

        // ── Authentication verify ──────────────────────────────────────────
        case 'auth_verify': {
            $challengeId = $postData['challengeId'] ?? '';
            $credJson    = $postData['credential']  ?? null;

            if (!$challengeId || !$credJson) {
                throw new RuntimeException('Missing challengeId or credential');
            }

            $row = fetchChallenge($challengeId, 'authentication');
            if (!$row) {
                throw new RuntimeException('Challenge not found or expired');
            }

            $challenge = base64_decode($row['challenge']);

            $credential = $serializer->deserialize(
                json_encode($credJson),
                PublicKeyCredential::class,
                'json'
            );

            if (!$credential->response instanceof AuthenticatorAssertionResponse) {
                throw new RuntimeException('Invalid response type');
            }

            // Look up the credential in the database
            $rawCredId = base64_encode($credential->rawId);
            $db        = getDb();
            $pkRow     = $db->prepare("SELECT * FROM passkeys WHERE credential_id = ?")->execute([$rawCredId]);
            $pkRow     = $db->prepare("SELECT * FROM passkeys WHERE credential_id = ?");
            $pkRow->execute([$rawCredId]);
            $passkeyRow = $pkRow->fetch();
            if (!$passkeyRow) {
                throw new RuntimeException('Unknown credential');
            }

            // public_key_cbor was stored as base64_encode(json) — decode before deserializing
            $source = $serializer->deserialize(
                base64_decode($passkeyRow['public_key_cbor']),
                PublicKeyCredentialSource::class,
                'json'
            );

            $requestOptions = PublicKeyCredentialRequestOptions::create(
                challenge:        $challenge,
                rpId:             getRpId(),
                userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            );

            $csmFactory = new CeremonyStepManagerFactory();
            $csmFactory->setAlgorithmManager(buildCoseAlgorithmManager());
            $csm = $csmFactory->requestCeremony();

            // Use named argument — first positional arg is PublicKeyCredentialSourceRepository, not CeremonyStepManager.
            // The first param of check() is credentialId (string|PublicKeyCredentialSource); pass $source directly.
            $validator = AuthenticatorAssertionResponseValidator::create(ceremonyStepManager: $csm);
            $newSource  = $validator->check(
                credentialId:                      $source,
                authenticatorAssertionResponse:    $credential->response,
                publicKeyCredentialRequestOptions: $requestOptions,
                request:                           getOrigin(),
                userHandle:                        null,
            );

            // Update sign count
            $db->prepare("UPDATE passkeys SET sign_count = ?, last_used = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE credential_id = ?")
               ->execute([$newSource->counter, $rawCredId]);

            // Create authenticated session
            $userId = (int) $passkeyRow['user_id'];
            $userRow = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
            $userRow->execute([$userId]);
            $userRecord = $userRow->fetch();
            if (!$userRecord) {
                throw new RuntimeException('Account not active');
            }

            createSession($userId);
            echo json_encode(['success' => true, 'redirect' => '/']);
            break;
        }

        default:
            throw new RuntimeException('Unknown action');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
