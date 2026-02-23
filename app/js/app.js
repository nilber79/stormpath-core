        const { createApp } = Vue;

        const vueApp = createApp({
            data() {
                return {
                    map: null,
                    allRoads: [],
                    visibleRoadIds: new Set(),
                    roadLayers: {},
                    reportSegmentLayers: {}, // Overlays for segment-specific reports
                    loading: false,
                    initializationState: {
                        roadsLoaded: false,
                        reportsLoaded: false,
                        mapPositioned: false,
                        initialRenderComplete: false
                    },
                    activeTab: 'reports',
                    reports: [],
                    expandedReportId: null,
                    showMobileSheet: false,
                    mobileSheetReport: null,
                    sidebarOpen: false,
                    currentZoom: 10,
                    statusTypes: [
                        { value: 'clear', label: 'Clear', color: '#10b981' },
                        { value: 'snow', label: 'Snow Covered', color: '#60a5fa' },
                        { value: 'ice-patches', label: 'Icy', color: '#a78bfa' },
                        { value: 'blocked-tree', label: 'Blocked - Tree', color: '#dc2626' },
                        { value: 'blocked-power', label: 'Blocked - Power Line', color: '#f59e0b' }
                    ],
                    showReportModal: false,
                    selectedSegments: [],
                    showSidebarReportForm: false,
                    selectedRoad: null,
                    newReport: {
                        status: '',
                        notes: '',
                        segment: null
                    },
                    reportClickLngLat: null,
                    locationPickMode: false,
                    _locationPickHidModal: false,
                    locationMarker: null,
                    selectionMode: false,
                    roadSegments: [],
                    segmentLayers: [],
                    segmentIndexMap: [], // Maps displayed segment indices to original indices
                    hasExistingReports: false, // Whether selected road has any reports
                    searchQuery: '',
                    searchResults: [],
                    statusFilter: 'all',
                    refreshInterval: null,
                    eventSource: null, // SSE connection for real-time updates
                    sseReconnectTimeout: null,
                    showAboutModal: false,
                    showHelpModal: false,
                    showDisclaimerModal: false,
                    showLegendDropdown: false,
                    showInfoDropdown: false,
                    showAdminDropdown: false,
                    mobileMenuOpen: false,
                    showMobileLegend: false,
                    showMobileInfo: false,
                    showMobileAdmin: false,
                    lastUserInteraction: 0, // Timestamp of last map interaction
                    isProcessingClick: false, // Flag to prevent rapid duplicate clicks
                    customLoadingMessage: null, // Custom message for streaming progress
                    // Notification settings
                    notificationsSupported: typeof Notification !== 'undefined',
                    notificationsEnabled: false,
                    notificationPermission: typeof Notification !== 'undefined' ? Notification.permission : 'denied',
                    notificationStatuses: ['blocked-tree', 'blocked-power'],
                    showNotificationSettings: false,
                    showMobileNotifications: false,
                    lastChangeId: 0, // Tracks SSE delta position
                    toasts: [], // Active foreground toast notifications
                    areaConfig: null, // Loaded from /area-config.json at startup
                    rebuildMeta: null  // Loaded from /api.php?action=get_metadata at startup
                }
            },
            computed: {
                isBlockedStatus() {
                    return ['blocked-tree', 'blocked-power'].includes(this.newReport.status);
                },

                isMobile() {
                    return window.innerWidth <= 768;
                },

                loadingMessage() {
                    // Use custom message if set (for streaming progress)
                    if (this.customLoadingMessage) {
                        return this.customLoadingMessage;
                    }

                    if (!this.initializationState.roadsLoaded) {
                        return 'Loading roads...';
                    } else if (!this.initializationState.reportsLoaded) {
                        return 'Loading reports...';
                    } else if (!this.initializationState.mapPositioned) {
                        return 'Positioning map...';
                    } else if (!this.initializationState.initialRenderComplete) {
                        return 'Rendering roads...';
                    }
                    return 'Loading...';
                },

                filteredReports() {
                    let filtered = this.reports;

                    // Filter by status
                    if (this.statusFilter !== 'all') {
                        filtered = filtered.filter(r => r.status === this.statusFilter);
                    }

                    // Filter by search query
                    if (this.searchQuery.length >= 2) {
                        const query = this.searchQuery.toLowerCase();
                        filtered = filtered.filter(r =>
                            r.road_name && r.road_name.toLowerCase().includes(query)
                        );
                    }

                    return filtered;
                },

                groupedReports() {
                    // Group reports by road name
                    const groups = {};
                    this.filteredReports.forEach(report => {
                        const roadName = report.road_name || 'Unnamed Road';
                        if (!groups[roadName]) {
                            groups[roadName] = [];
                        }
                        groups[roadName].push(report);
                    });

                    // Convert to array and sort by most recent report timestamp (cache timestamps)
                    return Object.keys(groups).map(roadName => {
                        const sortedReports = groups[roadName].sort((a, b) => {
                            const timeA = a._cachedTime || (a._cachedTime = new Date(a.timestamp).getTime());
                            const timeB = b._cachedTime || (b._cachedTime = new Date(b.timestamp).getTime());
                            return timeB - timeA;
                        });
                        return {
                            roadName: roadName,
                            reports: sortedReports
                        };
                    }).sort((a, b) => {
                        const timeA = a.reports[0]._cachedTime || (a.reports[0]._cachedTime = new Date(a.reports[0].timestamp).getTime());
                        const timeB = b.reports[0]._cachedTime || (b.reports[0]._cachedTime = new Date(b.reports[0].timestamp).getTime());
                        return timeB - timeA;
                    });
                },
                
                unreportedSearchResults() {
                    if (this.searchQuery.length < 2) return [];
                    
                    const query = this.searchQuery.toLowerCase();
                    const reportedRoadNames = new Set(this.reports.map(r => r.road_name));
                    
                    return this.searchResults.filter(road => 
                        !reportedRoadNames.has(road.name)
                    ).slice(0, 5); // Limit to 5 unreported roads
                }
            },
            async mounted() {
                this.loading = true; // Show loading immediately

                // Load area-specific configuration before initialising the map
                try {
                    this.areaConfig = await fetch('/area-config.json').then(r => r.json());
                } catch (e) {
                    console.error('Failed to load area-config.json:', e);
                    // Fallback defaults so the app still runs
                    this.areaConfig = { center: [0, 0], default_zoom: 10, proximity_radius_km: 80,
                                          pmtiles_file: 'map', contact_email: '' };
                }

                // Load rebuild metadata for admin dropdown stats (non-critical, fail silently)
                fetch('/api.php?action=get_metadata')
                    .then(r => r.json())
                    .then(d => { if (d.success) this.rebuildMeta = d.metadata; })
                    .catch(() => {});

                this.initMap();
                this.loadRoads();

                // Connect SSE first — its init event delivers all current reports and
                // marks reportsLoaded = true. Only fall back to loadReports() if SSE
                // hasn't delivered init within 5 seconds (slow connect or unavailable).
                this.connectSSE();
                setTimeout(() => {
                    if (!this.initializationState.reportsLoaded) {
                        this.loadReports();
                    }
                }, 5000);

                // Track map interactions to avoid refreshing during user activity
                // Track multiple event types to catch the entire click/touch sequence
                this.map.on('touchstart', (e) => {
                    this.lastUserInteraction = Date.now();
                });
                this.map.on('touchend', (e) => {
                    this.lastUserInteraction = Date.now();
                });
                this.map.on('mousedown', (e) => {
                    this.lastUserInteraction = Date.now();
                });
                this.map.on('click', (e) => {
                    this.lastUserInteraction = Date.now();
                });

                // Add direct canvas event listeners to bypass MapLibre event issues
                const canvas = this.map.getCanvas();
                canvas.addEventListener('touchstart', (e) => {
                }, { passive: true });
                canvas.addEventListener('touchend', (e) => {
                }, { passive: true });
                canvas.addEventListener('click', (e) => {
                });

                // Load notification preferences from localStorage
                this.loadNotificationPreferences();

                // Fallback polling in case SSE isn't working (checks every 30 seconds)
                this.refreshInterval = setInterval(() => {
                    // Only use fallback if SSE is disconnected
                    if (!this.eventSource || this.eventSource.readyState !== EventSource.OPEN) {
                        const timeSinceInteraction = Date.now() - this.lastUserInteraction;
                        if (timeSinceInteraction > 4000) {
                            console.log('SSE disconnected, using fallback polling');
                            this.loadReports();
                        }
                    }
                }, 30000);
            },
            beforeUnmount() {
                // Clean up SSE connection
                if (this.eventSource) {
                    this.eventSource.close();
                }
                if (this.sseReconnectTimeout) {
                    clearTimeout(this.sseReconnectTimeout);
                }
                if (this.refreshInterval) {
                    clearInterval(this.refreshInterval);
                }
            },
            methods: {
                checkInitializationComplete() {
                    // Check if all initialization steps are complete
                    if (this.initializationState.roadsLoaded &&
                        this.initializationState.reportsLoaded &&
                        this.initializationState.mapPositioned &&
                        this.initializationState.initialRenderComplete) {
                        this.loading = false;
                    }
                },

                initMap() {
                    // Register PMTiles protocol
                    let protocol = new pmtiles.Protocol();
                    maplibregl.addProtocol("pmtiles", protocol.tile);

                    // Use local self-hosted PMTiles file (baked into image, updated nightly)
                    this.map = new maplibregl.Map({
                        container: 'map',
                        center: this.areaConfig.center,
                        zoom: this.areaConfig.default_zoom - 1,
                        style: {
                            version: 8,
                            sources: {
                                pmtiles: {
                                    type: "vector",
                                    url: `pmtiles://tiles/${this.areaConfig.pmtiles_file}.pmtiles`,
                                    attribution: '© <a href="https://openmaptiles.org/">OpenMapTiles</a> © <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                                }
                            },
                            glyphs: "https://cdn.protomaps.com/fonts/pbf/{fontstack}/{range}.pbf",
                            layers: [
                                {id: "background", type: "background", paint: {"background-color": "#f8f6f2"}},
                                {id: "water", type: "fill", source: "pmtiles", "source-layer": "water", paint: {"fill-color": "#a0c8f0"}},
                                {id: "landuse_park", type: "fill", source: "pmtiles", "source-layer": "landuse", filter: ["==", "class", "park"], paint: {"fill-color": "#d4e7d4"}},
                                {id: "roads_casing", type: "line", source: "pmtiles", "source-layer": "transportation", paint: {"line-color": "#ffffff", "line-width": ["interpolate", ["exponential", 1.6], ["zoom"], 12, 3, 16, 8, 20, 20]}, layout: {"line-cap": "round", "line-join": "round"}},
                                {id: "roads", type: "line", source: "pmtiles", "source-layer": "transportation", paint: {"line-color": "#c0c0c0", "line-width": ["interpolate", ["exponential", 1.6], ["zoom"], 12, 1, 16, 4, 20, 12]}, layout: {"line-cap": "round", "line-join": "round"}},
                                {id: "buildings", type: "fill", source: "pmtiles", "source-layer": "building", paint: {"fill-color": "#d9d0c9", "fill-opacity": 0.7}},
                                {id: "road_labels", type: "symbol", source: "pmtiles", "source-layer": "transportation_name", layout: {"text-field": ["get", "name"], "text-font": ["Noto Sans Regular"], "text-size": 12, "symbol-placement": "line"}, paint: {"text-color": "#666", "text-halo-color": "#fff", "text-halo-width": 2}}
                            ]
                        }
                    });

                    // Add navigation controls (zoom buttons)
                    this.map.addControl(new maplibregl.NavigationControl(), 'top-right');

                    // Add click handler to close modals when clicking empty map areas
                    this.map.on('click', (e) => {
                        // Handle location pick mode — user is clicking to set blocked report coordinates
                        if (this.locationPickMode) {
                            this.updateLocationInNotes(e.lngLat.lat, e.lngLat.lng);
                            this.cancelLocationPick();
                            return;
                        }

                        // Interpolate query radius based on zoom (like MapLibre expressions)
                        // Smaller at low zoom to reduce overrun, larger at high zoom for forgiveness
                        const zoom = this.map.getZoom();
                        const radius = zoom < 13 ? 20 + (zoom - 10) * 3.33 : // 20-30px for zoom 10-13
                                      zoom < 15 ? 30 + (zoom - 13) * 5 :      // 30-40px for zoom 13-15
                                      zoom < 17 ? 40 + (zoom - 15) * 5 :      // 40-50px for zoom 15-17
                                      50;                                      // 50px for zoom 17+

                        const bbox = [
                            [e.point.x - radius, e.point.y - radius],
                            [e.point.x + radius, e.point.y + radius]
                        ];
                        const features = this.map.queryRenderedFeatures(bbox);

                        // Check specifically for report layers in the area
                        const reportLayers = this.map.getStyle().layers.filter(l => l.id.startsWith('report-segment-') && !l.id.endsWith('-click')).map(l => l.id);
                        const reportFeatures = this.map.queryRenderedFeatures(bbox, { layers: reportLayers });

                        // If we found a report segment, handle it directly here
                        if (reportFeatures.length > 0 && !this.selectionMode && !this.isProcessingClick) {
                            const reportLayerId = reportFeatures[0].layer.id;
                            // Extract report ID from layer ID: report-segment-{roadId}-{reportId}
                            const reportId = reportLayerId.split('-').pop();
                            const report = this.reports.find(r => r.id === reportId);
                            if (report) {
                                this.showExistingReport(report);
                                return; // Don't process further
                            }
                        }

                        const hasRoadFeature = features.some(f => f.layer.id && (f.layer.id.startsWith('road-') || f.layer.id.startsWith('segment-') || f.layer.id.startsWith('report-')));

                        // Handle segment selection in selection mode via global handler
                        if (this.selectionMode) {
                            const segmentFeature = features.find(f => f.layer.id && f.layer.id.startsWith('segment-'));
                            if (segmentFeature) {
                                // Get segment ID from feature properties
                                const segmentId = segmentFeature.properties.segmentId;
                                if (segmentId) {
                                    this.selectSegment(segmentId);
                                    return; // Don't process further
                                }
                            }
                        }

                        if (!hasRoadFeature) {
                            if (window.innerWidth <= 768 && this.showMobileSheet) {
                                this.closeMobileSheet();
                            }
                            if (this.selectionMode) {
                                this.cancelSelection();
                            }
                        }
                    });
                    
                    // Try to get user's location
                    if (navigator.geolocation) {
                        navigator.geolocation.getCurrentPosition(
                            (position) => {
                                const userLat = position.coords.latitude;
                                const userLon = position.coords.longitude;

                                // Check if user is reasonably close to the area
                                const distance = this.calculateDistance(
                                    userLat, userLon,
                                    this.areaConfig.center[1], this.areaConfig.center[0]
                                );

                                if (distance < this.areaConfig.proximity_radius_km) {
                                    this.map.flyTo({ center: [userLon, userLat], zoom: 14 });
                                } else {
                                    // User is far away, fly to area center
                                    this.map.flyTo({ center: this.areaConfig.center, zoom: this.areaConfig.default_zoom });
                                }

                                // Mark map as positioned after geolocation
                                this.initializationState.mapPositioned = true;
                                this.checkInitializationComplete();
                            },
                            (error) => {
                                // Location denied or unavailable, use default
                                console.log('Location access denied or unavailable');
                                // Still mark as positioned (using default)
                                this.initializationState.mapPositioned = true;
                                this.checkInitializationComplete();
                            },
                            {
                                timeout: 5000,
                                maximumAge: 60000
                            }
                        );
                    } else {
                        // No geolocation support, mark as positioned immediately
                        this.initializationState.mapPositioned = true;
                        this.checkInitializationComplete();
                    }

                    // Wait for map to load before adding map event handlers
                    this.map.on('load', () => {
                        // Force map to recalculate dimensions (fixes corner rendering issue)
                        this.map.resize();

                        // Add area boundary highlight
                        fetch('data/area_boundary_geojson.json')
                            .then(response => response.json())
                            .then(data => {
                                this.map.addSource('area-boundary', {
                                    type: 'geojson',
                                    data: data
                                });

                                // Add area boundary line (border)
                                this.map.addLayer({
                                    id: 'area-boundary-line',
                                    type: 'line',
                                    source: 'area-boundary',
                                    paint: {
                                        'line-color': '#f59e0b', // Orange/amber color
                                        'line-width': 3,
                                        'line-opacity': 0.8
                                    }
                                });

                                // Add subtle fill
                                this.map.addLayer({
                                    id: 'area-boundary-fill',
                                    type: 'fill',
                                    source: 'area-boundary',
                                    paint: {
                                        'fill-color': '#f59e0b',
                                        'fill-opacity': 0.05
                                    }
                                }, 'area-boundary-line'); // Place fill below the line
                            })
                            .catch(error => {
                                console.log('County boundary not available:', error);
                            });

                        this.map.on('zoomend', () => {
                            this.currentZoom = this.map.getZoom();
                            this.updateRoadWeights();
                        });
                    });
                },
                
                calculateDistance(lat1, lon1, lat2, lon2) {
                    // Haversine formula to calculate distance in km
                    const R = 6371;
                    const dLat = (lat2 - lat1) * Math.PI / 180;
                    const dLon = (lon2 - lon1) * Math.PI / 180;
                    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                            Math.sin(dLon/2) * Math.sin(dLon/2);
                    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                    return R * c;
                },
                
                updateRoadWeights() {
                    // Roads now use zoom-based interpolation in the layer definition
                    // Just update segment overlay weights
                    let reportWeight;
                    if (this.currentZoom < 11) {
                        reportWeight = 2.5;
                    } else if (this.currentZoom < 13) {
                        reportWeight = 3.5;
                    } else {
                        reportWeight = 5;
                    }

                    Object.keys(this.reportSegmentLayers).forEach(layerId => {
                        if (this.map.getLayer(layerId)) {
                            this.map.setPaintProperty(layerId, 'line-width', reportWeight);
                        }
                    });
                },
                
                getRoadWeight() {
                    // Base road weight (gray roads)
                    if (this.currentZoom < 11) {
                        return 1.5;
                    } else if (this.currentZoom < 13) {
                        return 2;
                    } else {
                        return 3;
                    }
                },
                
                async loadRoads() {
                    this.loading = true;
                    this.customLoadingMessage = null; // Reset custom message

                    try {
                        // Try streaming JSONL first for progressive loading
                        const useStreaming = true; // Set to false to use legacy method

                        if (useStreaming) {
                            await this.loadRoadsStreaming();
                        } else {
                            await this.loadRoadsLegacy();
                        }

                        // Mark roads as loaded
                        this.initializationState.roadsLoaded = true;
                        this.initializationState.initialRenderComplete = true;
                        this.checkInitializationComplete();
                    } catch (error) {
                        console.error('Error loading roads:', error);

                        // If streaming failed, fall back to legacy method
                        if (error.message.includes('streaming')) {
                            console.log('Falling back to legacy loading method');
                            try {
                                await this.loadRoadsLegacy();
                                this.initializationState.roadsLoaded = true;
                                this.initializationState.initialRenderComplete = true;
                                this.checkInitializationComplete();
                            } catch (fallbackError) {
                                console.error('Fallback loading also failed:', fallbackError);
                                alert('Failed to load road data. Please refresh the page.');
                                this.loading = false;
                            }
                        } else {
                            alert('Failed to load road data. Please refresh the page.');
                            this.loading = false;
                        }
                    }
                },

                async loadRoadsStreaming() {
                    const timestamp = new Date().getTime();
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 30000);
                    let response;
                    try {
                        response = await fetch(`data/roads_optimized.jsonl`, { signal: controller.signal });
                    } finally {
                        clearTimeout(timeoutId);
                    }

                    if (!response.ok || !response.body) {
                        throw new Error('Streaming not supported or request failed');
                    }

                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    let roadCount = 0;

                    // Collect all roads in memory first (don't update map progressively)
                    while (true) {
                        const { done, value } = await reader.read();

                        if (done) break;

                        // Decode chunk and add to buffer
                        buffer += decoder.decode(value, { stream: true });

                        // Process complete lines
                        const lines = buffer.split('\n');
                        buffer = lines.pop(); // Keep incomplete line in buffer

                        for (const line of lines) {
                            if (line.trim()) {
                                try {
                                    const element = JSON.parse(line);
                                    const road = {
                                        id: element.id,
                                        name: element.tags?.name || 'Unnamed Road',
                                        type: element.type || 'other',
                                        geometry: element.geometry,
                                        segments: element.segments || null
                                    };

                                    this.allRoads.push(road);
                                    roadCount++;

                                    // Update progress every 100 roads
                                    if (roadCount % 100 === 0) {
                                        this.customLoadingMessage = `Loading roads... ${roadCount}`;
                                    }
                                } catch (parseError) {
                                    console.error('Error parsing road:', parseError, line);
                                }
                            }
                        }
                    }

                    this.customLoadingMessage = `Adding ${roadCount} roads to map...`;

                    // Wait for map to load, then add all roads at once
                    if (this.map.loaded()) {
                        this.addRoadsLayer();
                    } else {
                        this.map.once('load', () => this.addRoadsLayer());
                    }

                    // Clear custom message after a short delay
                    setTimeout(() => {
                        this.customLoadingMessage = null;
                    }, 1000);
                },

                async loadRoadsLegacy() {
                    const timestamp = new Date().getTime();
                    const response = await fetch(`api.php?action=get_roads&_=${timestamp}`);
                    const data = await response.json();

                    if (!data.elements) {
                        throw new Error('Invalid data format from API');
                    }

                    this.allRoads = data.elements.map((element) => ({
                        id: element.id,
                        name: element.tags?.name || 'Unnamed Road',
                        type: element.type || 'other',
                        geometry: element.geometry,
                        segments: element.segments || null
                    }));

                    // Wait for map to load, then add all roads as a single GeoJSON source
                    if (this.map.loaded()) {
                        this.addRoadsLayer();
                    } else {
                        this.map.once('load', () => this.addRoadsLayer());
                    }
                },

                addRoadsLayer() {
                    // Convert all roads to a GeoJSON FeatureCollection
                    const features = this.allRoads.map(road => ({
                        type: 'Feature',
                        id: road.id,
                        properties: {
                            id: road.id,
                            name: road.name,
                            type: road.type
                        },
                        geometry: {
                            type: 'LineString',
                            coordinates: road.geometry.map(coord => [coord[1], coord[0]]) // [lng, lat]
                        }
                    }));

                    const geojson = {
                        type: 'FeatureCollection',
                        features: features
                    };

                    // Add source
                    this.map.addSource('roads', {
                        type: 'geojson',
                        data: geojson
                    });

                    // Add base road layer
                    this.map.addLayer({
                        id: 'roads-line',
                        type: 'line',
                        source: 'roads',
                        paint: {
                            'line-color': '#94a3b8',
                            'line-width': ['interpolate', ['linear'], ['zoom'], 10, 1.5, 13, 2, 16, 3],
                            'line-opacity': 0.5
                        }
                    });

                    // Add invisible click target layer (thicker for easier clicking)
                    this.map.addLayer({
                        id: 'roads-click',
                        type: 'line',
                        source: 'roads',
                        paint: {
                            'line-color': 'transparent',
                            'line-width': ['interpolate', ['linear'], ['zoom'], 10, 15, 13, 20, 16, 25],
                            'line-opacity': 0
                        }
                    });

                    // Add click handler
                    this.map.on('click', 'roads-click', (e) => {
                        // Don't handle road clicks when in segment selection mode
                        if (this.selectionMode) {
                            return;
                        }

                        // Check if clicking on a report segment layer (don't start submission if so)
                        // Query an area around the click point for better detection
                        // Use larger area on mobile for touch accuracy
                        const isMobile = window.innerWidth <= 768;
                        const tolerance = isMobile ? 25 : 10; // Larger touch target on mobile
                        const bbox = [
                            [e.point.x - tolerance, e.point.y - tolerance],
                            [e.point.x + tolerance, e.point.y + tolerance]
                        ];
                        const clickedLayers = this.map.queryRenderedFeatures(bbox);
                        const hasReportSegment = clickedLayers.some(layer =>
                            layer.layer.id.startsWith('report-segment-')
                        );
                        if (hasReportSegment) {
                            return; // Let the report segment handler deal with it
                        }

                        if (e.features && e.features.length > 0) {
                            const feature = e.features[0];
                            const road = this.allRoads.find(r => r.id == feature.properties.id);
                            if (road) {
                                this.handleRoadClick(road, e);
                            }
                        }
                    });

                    // Change cursor on hover
                    this.map.on('mouseenter', 'roads-click', () => {
                        this.map.getCanvas().style.cursor = 'pointer';
                    });

                    this.map.on('mouseleave', 'roads-click', () => {
                        this.map.getCanvas().style.cursor = '';
                    });

                    // Load reports after roads are added
                    this.loadReports();
                },
                
                updateVisibleRoads() {
                    // No longer needed - all roads are in a single layer
                    // Just trigger report rendering updates if needed
                },
                
                renderRoad(road) {
                    // No longer creating individual layers - all roads are in one layer
                    // Just render report segments if needed
                    this.renderReportSegments(road);
                },
                
                renderReportSegments(road) {
                    // Clear existing segment overlays for this road first
                    Object.keys(this.reportSegmentLayers).forEach(key => {
                        if (key.startsWith(`report-segment-${road.id}-`)) {
                            // Remove event handlers before removing layer
                            this.map.off('click', key);
                            this.map.off('mouseenter', key);
                            this.map.off('mouseleave', key);

                            if (this.map.getLayer(key)) this.map.removeLayer(key);

                            // Only remove source for main layers, not click layers (they share the source)
                            if (!key.endsWith('-click') && this.map.getSource(key)) {
                                this.map.removeSource(key);
                            }

                            delete this.reportSegmentLayers[key];
                        }
                    });

                    // Find all reports for this road
                    const roadReports = this.reports.filter(r =>
                        r.road_id == road.id || r.road_name === road.name
                    );

                    if (roadReports.length === 0) return;

                    // Sort by timestamp, most recent first
                    roadReports.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));

                    // Get weight based on zoom (thicker for reported segments)
                    // Make thicker on mobile for better touch targets
                    const isMobile = window.innerWidth <= 768;

                    // Use interpolated widths that scale with zoom
                    // Make VERY thick on mobile for easy touching - no invisible layers needed
                    const lineWidthExpression = isMobile ?
                        ['interpolate', ['linear'], ['zoom'],
                            10, 12,   // At zoom 10: 12px
                            13, 20,   // At zoom 13: 20px
                            16, 30,   // At zoom 16: 30px
                            18, 40    // At zoom 18: 40px (very easy to tap)
                        ] :
                        ['interpolate', ['linear'], ['zoom'],
                            10, 2.5,
                            13, 4,
                            16, 6,
                            18, 8
                        ];

                    roadReports.forEach(report => {
                        const layerKey = `report-segment-${road.id}-${report.id}`;

                        // Use the report's geometry (which is segment-specific)
                        if (report.geometry && report.geometry.length > 0) {
                            const geojson = {
                                type: 'Feature',
                                properties: {
                                    reportId: report.id
                                },
                                geometry: {
                                    type: 'LineString',
                                    coordinates: report.geometry.map(coord => [coord[1], coord[0]])
                                }
                            };

                            // Add source
                            this.map.addSource(layerKey, {
                                type: 'geojson',
                                data: geojson
                            });

                            // Add thick visible line layer - what you see is what you click
                            this.map.addLayer({
                                id: layerKey,
                                type: 'line',
                                source: layerKey,
                                paint: {
                                    'line-color': this.getStatusColor(report.status),
                                    'line-width': lineWidthExpression,
                                    'line-opacity': 0.9
                                }
                            });

                            // Add click handler directly to the visible layer
                            this.map.on('click', layerKey, (e) => {
                                if (this.selectionMode) {
                                    return;
                                }
                                if (this.isProcessingClick) {
                                    return;
                                }
                                this.showExistingReport(report);
                            });

                            // Change cursor on hover
                            this.map.on('mouseenter', layerKey, () => {
                                this.map.getCanvas().style.cursor = 'pointer';
                            });

                            this.map.on('mouseleave', layerKey, () => {
                                this.map.getCanvas().style.cursor = '';
                            });

                            this.reportSegmentLayers[layerKey] = true;
                        }
                    });
                },
                
                showExistingReport(report) {
                    // Prevent rapid duplicate clicks
                    if (this.isProcessingClick) {
                        return;
                    }
                    this.isProcessingClick = true;

                    // On mobile, show bottom sheet and close sidebar. On desktop, use sidebar
                    if (window.innerWidth <= 768) {
                        this.mobileSheetReport = report;
                        this.showMobileSheet = true;
                        this.sidebarOpen = false; // Close sidebar on mobile
                        this.focusOnReport(report);
                        // Reset flag after animation completes
                        setTimeout(() => {
                            this.isProcessingClick = false;
                        }, 350);
                    } else {
                        // Desktop: Switch to reports tab, expand the report, scroll to it, and focus on map
                        this.activeTab = 'reports';
                        this.sidebarOpen = true;
                        this.expandedReportId = report.id;
                        this.$nextTick(() => {
                            this.scrollToRoadGroup(report.road_name);
                        });
                        this.focusOnReport(report);
                        // Reset flag immediately on desktop
                        this.isProcessingClick = false;
                    }
                },

                scrollToRoadGroup(roadName) {
                    // Scroll the reports list to show the clicked road's reports
                    const container = this.$refs.reportsListContainer;
                    if (!container) return;

                    const groupElement = container.querySelector(`[data-road-name="${roadName}"]`);
                    if (groupElement) {
                        groupElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                },
                
                removeRoad(roadId) {
                    // No longer removing individual road layers
                    // Just remove segment overlays if needed
                    Object.keys(this.reportSegmentLayers).forEach(key => {
                        if (key.startsWith(`report-segment-${roadId}-`)) {
                            if (this.map.getLayer(key)) this.map.removeLayer(key);
                            // Only remove source for main layers, not click layers
                            if (!key.endsWith('-click') && this.map.getSource(key)) {
                                this.map.removeSource(key);
                            }
                            delete this.reportSegmentLayers[key];
                        }
                    });
                },

                handleRoadClick(road, clickEvent = null) {
                    // Store click coordinates for GPS pre-population in blocked reports
                    if (clickEvent && clickEvent.lngLat) {
                        this.reportClickLngLat = clickEvent.lngLat;
                    }

                    // If already in selection mode, ignore clicks on other roads
                    if (this.selectionMode && this.selectedRoad && this.selectedRoad.id !== road.id) {
                        return;
                    }

                    // If already showing a report form, ignore clicks on other roads
                    if (this.showSidebarReportForm && this.selectedRoad && this.selectedRoad.id !== road.id) {
                        return;
                    }

                    // Proceed with normal reporting workflow
                    // Note: clicking on report segment overlays (colored segments) will show existing reports via renderReportSegments handlers
                    this.selectedRoad = road;

                    // All roads should have pre-calculated segments with unique IDs from roads_optimized.json
                    // Fallback to dynamic calculation only if data is missing (shouldn't happen after rebuild)
                    let allSegments;
                    if (road.segments && road.segments.length > 0) {
                        allSegments = road.segments;
                    } else {
                        allSegments = this.calculateSegments(road.geometry, road.id);
                    }

                    // Filter out segments that already have reports
                    const roadReports = this.reports.filter(r => r.road_id === road.id);
                    const hasReports = roadReports.length > 0;

                    if (hasReports) {
                        // Check if road has an "entire" report (blocks all segments)
                        const hasEntireReport = roadReports.some(r => r.segment === 'entire');

                        if (hasEntireReport) {
                            // Can't report on any segment
                            this.roadSegments = [];
                            this.segmentIndexMap = [];
                        } else {
                            // Filter out segments with specific reports using unique segment IDs
                            this.roadSegments = allSegments.filter(segment => {
                                const segmentId = segment.id;

                                // Check if this segment ID exists in any report's segmentIds array
                                const isReported = roadReports.some(r => {
                                    return r.segmentIds && r.segmentIds.includes(segmentId);
                                });

                                return !isReported;
                            });

                            // Store segment IDs for mapping (no longer using indices)
                            this.segmentIndexMap = this.roadSegments.map(segment => segment.id);
                        }
                    } else {
                        // No reports, show all segments
                        this.roadSegments = allSegments;
                        this.segmentIndexMap = allSegments.map(segment => segment.id);
                    }

                    // Apply proximity-based filtering for long roads
                    // Show only ~3 segments around where user clicked
                    if (clickEvent && this.roadSegments.length > 3) {
                        const clickLngLat = clickEvent.lngLat;
                        const closestSegmentIndex = this.findClosestSegment(this.roadSegments, clickLngLat);
                        const proximitySegments = this.getAdjacentSegments(this.roadSegments, closestSegmentIndex);

                        // Filter roadSegments to only include proximity segments
                        this.roadSegments = proximitySegments;
                        this.segmentIndexMap = proximitySegments.map(segment => segment.id);
                    }

                    // Store whether road has any reports (for hiding "All Segments" button)
                    this.hasExistingReports = hasReports;

                    if (this.roadSegments.length <= 1) {
                        // Short road or single remaining segment — highlight it
                        const layerId = 'highlight-entire-road';
                        const sourceId = 'highlight-entire-road-source';

                        // If one segment remains after filtering, highlight just that segment;
                        // otherwise highlight the entire road geometry
                        const highlightGeometry = (hasReports && this.roadSegments.length === 1)
                            ? this.roadSegments[0].geometry
                            : road.geometry;

                        const geojson = {
                            type: 'Feature',
                            geometry: {
                                type: 'LineString',
                                coordinates: highlightGeometry.map(coord => [coord[1], coord[0]])
                            }
                        };

                        this.map.addSource(sourceId, {
                            type: 'geojson',
                            data: geojson
                        });

                        this.map.addLayer({
                            id: layerId,
                            type: 'line',
                            source: sourceId,
                            paint: {
                                'line-color': '#2563eb',
                                'line-width': 10,
                                'line-opacity': 1
                            }
                        });

                        this.segmentLayers = [layerId];

                        // Zoom to fit the highlighted geometry
                        const coords = highlightGeometry.map(coord => [coord[1], coord[0]]);
                        const bounds = coords.reduce((bounds, coord) => {
                            return bounds.extend(coord);
                        }, new maplibregl.LngLatBounds(coords[0], coords[0]));

                        this.map.fitBounds(bounds, {
                            padding: {top: 150, bottom: 80, left: 80, right: 80},
                            maxZoom: 15
                        });

                        if (hasReports && this.roadSegments.length === 1) {
                            // One remaining unreported segment on a multi-segment road —
                            // auto-select it as a specific segment, not the entire road
                            this.newReport = {
                                status: '',
                                notes: '',
                                segment: 'single',
                                singleSegment: this.roadSegments[0]
                            };
                        } else {
                            // Naturally short/single-segment road with no existing reports
                            this.newReport = {
                                status: '',
                                notes: '',
                                segment: 'entire'
                            };
                        }

                        // Desktop: use sidebar, Mobile: use modal
                        if (window.innerWidth > 768) {
                            this.showSidebarReportForm = true;
                            this.activeTab = 'submit';
                        } else {
                            this.showReportModal = true;
                        }
                    } else {
                        // Show segment selection
                        this.selectionMode = true;
                        this.selectedSegments = []; // Reset selection
                        this.displaySegments();

                        // Zoom to fit only the visible/filtered segments, not the entire road
                        // Collect all points from the filtered segments
                        const visiblePoints = [];
                        this.roadSegments.forEach(segment => {
                            visiblePoints.push(...segment.geometry);
                        });

                        const coords = visiblePoints.map(coord => [coord[1], coord[0]]);
                        const bounds = coords.reduce((bounds, coord) => {
                            return bounds.extend(coord);
                        }, new maplibregl.LngLatBounds(coords[0], coords[0]));

                        this.map.fitBounds(bounds, {
                            padding: {top: 150, bottom: 80, left: 80, right: 80},
                            maxZoom: 15
                        });
                    }
                },

                closeMobileSheet() {
                    this.showMobileSheet = false;
                    setTimeout(() => {
                        this.mobileSheetReport = null;
                        // Ensure click flag is reset when sheet fully closes
                        this.isProcessingClick = false;
                    }, 300);
                },
                
                handleMapClick(e) {
                    // This is called when clicking the overlay
                    // Only close if we're actually clicking the overlay, not a road
                    // The overlay is behind roads, so if we get here, it means
                    // the user clicked an empty area
                    if (this.showMobileSheet) {
                        this.closeMobileSheet();
                    }
                    if (this.selectionMode) {
                        this.cancelSelection();
                    }
                },
                
                toggleSegmentSelection(segmentId) {
                    const idx = this.selectedSegments.indexOf(segmentId);
                    if (idx > -1) {
                        this.selectedSegments.splice(idx, 1);
                    } else {
                        this.selectedSegments.push(segmentId);
                    }
                },
                

                toggleReportExpansion(report) {
                    // On mobile, show bottom sheet and close sidebar
                    if (window.innerWidth <= 768) {
                        this.mobileSheetReport = report;
                        this.showMobileSheet = true;
                        this.sidebarOpen = false; // Close sidebar so map is visible
                        this.focusOnReport(report);
                        return;
                    }

                    // Desktop: toggle expansion
                    if (this.expandedReportId === report.id) {
                        this.expandedReportId = null;
                    } else {
                        this.expandedReportId = report.id;
                        this.focusOnReport(report);
                    }
                },

                calculateSegments(geometry, roadId) {
                    const totalPoints = geometry.length;

                    // For very short roads, just use entire road
                    if (totalPoints < 15) {
                        return [{
                            id: `${roadId}-1`,
                            description: 'All visible segments',
                            geometry: geometry
                        }];
                    }

                    // Find potential intersection points by looking for sharp direction changes
                    // This approximates where roads might intersect
                    const intersectionIndices = this.findIntersectionPoints(geometry);

                    // If we found good intersection points, use them
                    if (intersectionIndices.length > 0 && intersectionIndices.length <= 4) {
                        return this.createSegmentsFromIntersections(geometry, intersectionIndices, roadId);
                    }

                    // Otherwise, fall back to distance-based segmentation
                    // Break every 2 miles (~3.2 km) if road is long
                    const segmentsByDistance = this.createSegmentsByDistance(geometry, roadId);

                    return segmentsByDistance;
                },
                
                findIntersectionPoints(geometry) {
                    const intersections = [];
                    const minDistance = 0.002; // ~200 meters minimum between intersections
                    
                    for (let i = 2; i < geometry.length - 2; i++) {
                        const prev = geometry[i - 2];
                        const curr = geometry[i];
                        const next = geometry[i + 2];
                        
                        // Calculate bearing change
                        const bearing1 = this.calculateBearing(prev, curr);
                        const bearing2 = this.calculateBearing(curr, next);
                        const bearingChange = Math.abs(bearing2 - bearing1);
                        
                        // Significant bearing change suggests an intersection
                        // Look for changes > 30 degrees but < 150 degrees (not U-turns)
                        if (bearingChange > 30 && bearingChange < 150) {
                            // Check if far enough from last intersection
                            if (intersections.length === 0 || 
                                this.pointDistance(geometry[intersections[intersections.length - 1]], curr) > minDistance) {
                                intersections.push(i);
                            }
                        }
                    }
                    
                    // Limit to max 3 segments (so max 2 intersection points)
                    if (intersections.length > 2) {
                        // Keep the most evenly spaced ones
                        const step = Math.floor(intersections.length / 2);
                        return [intersections[step], intersections[intersections.length - step]];
                    }
                    
                    return intersections;
                },
                
                calculateBearing(point1, point2) {
                    const lat1 = point1[0] * Math.PI / 180;
                    const lat2 = point2[0] * Math.PI / 180;
                    const dLon = (point2[1] - point1[1]) * Math.PI / 180;
                    
                    const y = Math.sin(dLon) * Math.cos(lat2);
                    const x = Math.cos(lat1) * Math.sin(lat2) - 
                             Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLon);
                    
                    let bearing = Math.atan2(y, x) * 180 / Math.PI;
                    bearing = (bearing + 360) % 360;
                    
                    return bearing;
                },
                
                pointDistance(point1, point2) {
                    const lat1 = point1[0];
                    const lon1 = point1[1];
                    const lat2 = point2[0];
                    const lon2 = point2[1];
                    
                    const dLat = (lat2 - lat1) * Math.PI / 180;
                    const dLon = (lon2 - lon1) * Math.PI / 180;
                    
                    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                             Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                             Math.sin(dLon/2) * Math.sin(dLon/2);
                    
                    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                    return 6371 * c; // Distance in km
                },
                
                createSegmentsFromIntersections(geometry, intersectionIndices, roadId) {
                    const segments = [];
                    let startIdx = 0;

                    intersectionIndices.forEach((intersectionIdx, i) => {
                        const segmentGeometry = geometry.slice(startIdx, intersectionIdx + 1);
                        segments.push({
                            id: `${roadId}-${i + 1}`,
                            description: `Segment ${i + 1} of ${intersectionIndices.length + 1}`,
                            geometry: segmentGeometry
                        });
                        startIdx = intersectionIdx;
                    });

                    // Add final segment
                    const finalSegment = geometry.slice(startIdx);
                    segments.push({
                        id: `${roadId}-${segments.length + 1}`,
                        description: `Segment ${segments.length + 1} of ${intersectionIndices.length + 1}`,
                        geometry: finalSegment
                    });

                    return segments;
                },
                
                createSegmentsByDistance(geometry, roadId) {
                    const maxSegmentDistance = 3.2; // 2 miles in km
                    const segments = [];
                    let currentSegment = [geometry[0]];
                    let segmentDistance = 0;

                    for (let i = 1; i < geometry.length; i++) {
                        const distance = this.pointDistance(geometry[i-1], geometry[i]);
                        segmentDistance += distance;
                        currentSegment.push(geometry[i]);

                        // If we've gone more than 2 miles and have at least 2 segments left to create
                        if (segmentDistance >= maxSegmentDistance && segments.length < 2) {
                            segments.push({
                                id: `${roadId}-${segments.length + 1}`,
                                description: `Segment ${segments.length + 1}`,
                                geometry: [...currentSegment]
                            });
                            currentSegment = [geometry[i]];
                            segmentDistance = 0;
                        }
                    }

                    // Add the final segment
                    if (currentSegment.length > 1) {
                        segments.push({
                            id: `${roadId}-${segments.length + 1}`,
                            description: `Segment ${segments.length + 1}`,
                            geometry: currentSegment
                        });
                    }

                    // Update descriptions and IDs to show total count
                    if (segments.length > 1) {
                        segments.forEach((seg, i) => {
                            seg.id = `${roadId}-${i + 1}`;
                            seg.description = `Segment ${i + 1} of ${segments.length}`;
                        });
                    } else if (segments.length === 1) {
                        segments[0].id = `${roadId}-1`;
                        segments[0].description = 'All visible segments';
                    }

                    return segments.length > 0 ? segments : [{
                        id: `${roadId}-1`,
                        description: 'All visible segments',
                        geometry: geometry
                    }];
                },

                findClosestSegment(segments, clickLngLat) {
                    // Find which segment is closest to the click point
                    // Returns the index of the closest segment
                    let closestIndex = 0;
                    let minDistance = Infinity;

                    segments.forEach((segment, index) => {
                        // Calculate distance to segment's midpoint
                        const midIndex = Math.floor(segment.geometry.length / 2);
                        const midPoint = segment.geometry[midIndex];

                        // Calculate distance using haversine formula
                        const distance = this.haversineDistance(
                            clickLngLat.lat,
                            clickLngLat.lng,
                            midPoint[0],
                            midPoint[1]
                        );

                        if (distance < minDistance) {
                            minDistance = distance;
                            closestIndex = index;
                        }
                    });

                    return closestIndex;
                },

                getAdjacentSegments(segments, centerIndex) {
                    // Get ~3 segments around the center index
                    // For segments at the start/end, adjust to still show 3 total
                    const totalSegments = segments.length;

                    if (totalSegments <= 3) {
                        // Show all segments if 3 or fewer
                        return segments;
                    }

                    // Determine how many segments to show before and after
                    let startIndex, endIndex;

                    if (centerIndex === 0) {
                        // At the start, show first 3 segments
                        startIndex = 0;
                        endIndex = 2;
                    } else if (centerIndex === totalSegments - 1) {
                        // At the end, show last 3 segments
                        startIndex = totalSegments - 3;
                        endIndex = totalSegments - 1;
                    } else {
                        // In the middle, show center ± 1 (3 total)
                        startIndex = centerIndex - 1;
                        endIndex = centerIndex + 1;
                    }

                    // Return the subset of segments
                    return segments.slice(startIndex, endIndex + 1);
                },

                haversineDistance(lat1, lon1, lat2, lon2) {
                    // Calculate distance between two points in miles
                    const R = 3959; // Earth's radius in miles
                    const dLat = (lat2 - lat1) * Math.PI / 180;
                    const dLon = (lon2 - lon1) * Math.PI / 180;
                    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                            Math.sin(dLon / 2) * Math.sin(dLon / 2);
                    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
                    return R * c;
                },

                displaySegments() {
                    this.clearSegmentLayers();

                    // Clear ALL report segment overlays for this road to prevent blocking
                    if (this.selectedRoad) {
                        const roadId = this.selectedRoad.id;

                        // Get all keys that match this road BEFORE any modifications
                        const keysToRemove = Object.keys(this.reportSegmentLayers).filter(key =>
                            key.startsWith(`report-segment-${roadId}-`)
                        );


                        // Remove layers and sources from map
                        keysToRemove.forEach(layerId => {
                            try {
                                // Remove ALL event listeners for this layer (not just the ones we know about)
                                this.map.off('click', layerId);
                                this.map.off('mouseenter', layerId);
                                this.map.off('mouseleave', layerId);
                                this.map.off('mousedown', layerId);
                                this.map.off('mouseup', layerId);
                                this.map.off('mousemove', layerId);

                                if (this.map.getLayer(layerId)) {
                                    this.map.removeLayer(layerId);
                                }
                                // Only remove source for main layers, not click layers
                                if (!layerId.endsWith('-click') && this.map.getSource(layerId)) {
                                    this.map.removeSource(layerId);
                                }
                            } catch (e) {
                                console.warn(`Error removing ${layerId}:`, e);
                            }
                            // Remove from tracking
                            delete this.reportSegmentLayers[layerId];
                        });


                        // Force the map to stop any drag operations and reset interaction state
                        this.map.stop();
                    }

                    // Create layers and attach handlers immediately
                    // Use segment IDs instead of indices for tracking
                    this.segmentLayers = this.roadSegments.map((segment, index) => {
                            const segmentId = segment.id;
                            const layerId = `segment-${index}`; // Keep index for layer name
                            const sourceId = `segment-source-${index}`;

                            const geojson = {
                                type: 'Feature',
                                properties: { segmentId, selected: false },
                                geometry: {
                                    type: 'LineString',
                                    coordinates: segment.geometry.map(coord => [coord[1], coord[0]])
                                }
                            };

                            // Check if source already exists and remove it
                            if (this.map.getSource(sourceId)) {
                                if (this.map.getLayer(layerId)) this.map.removeLayer(layerId);
                                this.map.removeSource(sourceId);
                            }

                            this.map.addSource(sourceId, {
                                type: 'geojson',
                                data: geojson
                            });

                            this.map.addLayer({
                                id: layerId,
                                type: 'line',
                                source: sourceId,
                                paint: {
                                    'line-color': this.getSegmentColor(index),
                                    'line-width': 8,
                                    'line-opacity': 0.4
                                }
                            });

                            // Add hover effects
                            this.map.on('mouseenter', layerId, () => {
                                if (!this.selectedSegments.includes(segmentId)) {
                                    this.map.setPaintProperty(layerId, 'line-width', 10);
                                    this.map.setPaintProperty(layerId, 'line-opacity', 0.6);
                                }
                                this.map.getCanvas().style.cursor = 'pointer';
                            });

                            this.map.on('mouseleave', layerId, () => {
                                if (!this.selectedSegments.includes(segmentId)) {
                                    this.map.setPaintProperty(layerId, 'line-width', 8);
                                    this.map.setPaintProperty(layerId, 'line-opacity', 0.4);
                                }
                                this.map.getCanvas().style.cursor = '';
                            });

                            // Click handling is done via global map handler above
                            // No per-layer click handler needed

                        return layerId;
                    });
                },
                
                highlightSelectedSegments() {
                    // Update visual state of segments based on selection
                    this.segmentLayers.forEach((layerId, index) => {
                        if (this.map.getLayer(layerId)) {
                            const segmentId = this.roadSegments[index]?.id;
                            if (this.selectedSegments.includes(segmentId)) {
                                this.map.setPaintProperty(layerId, 'line-width', 12);
                                this.map.setPaintProperty(layerId, 'line-opacity', 1);
                            } else {
                                this.map.setPaintProperty(layerId, 'line-width', 8);
                                this.map.setPaintProperty(layerId, 'line-opacity', 0.4);
                            }
                        }
                    });
                },

                selectSegment(segmentId) {
                    // Toggle segment selection (using segment ID instead of index)
                    this.toggleSegmentSelection(segmentId);
                    this.highlightSelectedSegments();
                },

                selectEntireRoad() {
                    // Report the entire road, not just visible segments
                    this.selectionMode = false;
                    this.newReport = {
                        status: '',
                        notes: '',
                        segment: 'entire'
                    };
                    if (window.innerWidth > 768) {
                        this.showSidebarReportForm = true;
                        this.activeTab = 'submit';
                    } else {
                        this.showReportModal = true;
                    }
                },

                selectAllSegments() {
                    // Select all visible/highlighted segments and proceed to report form
                    this.selectedSegments = this.roadSegments.map(segment => segment.id);
                    this.highlightSelectedSegments();

                    // Automatically continue to report submission
                    this.continueWithSelection();
                },
                
                continueWithSelection() {
                    if (this.selectedSegments.length === 0) return;

                    this.selectionMode = false;

                    // selectedSegments now contains segment IDs (e.g., "292422956-1", "292422956-2")
                    const selectedIds = this.selectedSegments;

                    // Determine what to report based on selection
                    if (selectedIds.length === 1) {
                        // Single segment selected
                        const segment = this.roadSegments.find(s => s.id === selectedIds[0]);
                        this.newReport = {
                            status: '',
                            notes: '',
                            segment: 'single',
                            singleSegment: segment
                        };
                    } else {
                        // Multiple segments selected - group consecutive segments using segment IDs
                        const sortedIds = this.sortSegmentIds(selectedIds);
                        const groups = this.groupConsecutiveSegmentIds(sortedIds);

                        if (groups.length === 1) {
                            // One consecutive group - combine into single report
                            const combinedSegment = this.combineSegmentsByIds(groups[0]);
                            this.newReport = {
                                status: '',
                                notes: '',
                                segment: 'combined',
                                combinedSegment: combinedSegment
                            };
                        } else {
                            // Multiple non-consecutive groups - will create multiple reports
                            this.newReport = {
                                status: '',
                                notes: '',
                                segment: 'multiple',
                                segmentGroups: groups.map(group => this.combineSegmentsByIds(group))
                            };
                        }
                    }

                    // Desktop: use sidebar, Mobile: use modal
                    if (window.innerWidth > 768) {
                        this.showSidebarReportForm = true;
                        this.activeTab = 'submit';
                    } else {
                        this.showReportModal = true;
                    }
                },

                sortSegmentIds(segmentIds) {
                    // Sort segment IDs by their segment number (e.g., "292422956-1" -> 1)
                    return [...segmentIds].sort((a, b) => {
                        const numA = parseInt(a.split('-')[1]);
                        const numB = parseInt(b.split('-')[1]);
                        return numA - numB;
                    });
                },

                groupConsecutiveSegmentIds(sortedIds) {
                    // Group consecutive segment IDs into arrays
                    const groups = [];
                    let currentGroup = [sortedIds[0]];

                    for (let i = 1; i < sortedIds.length; i++) {
                        const currentNum = parseInt(sortedIds[i].split('-')[1]);
                        const previousNum = parseInt(sortedIds[i - 1].split('-')[1]);

                        if (currentNum === previousNum + 1) {
                            // Consecutive - add to current group
                            currentGroup.push(sortedIds[i]);
                        } else {
                            // Not consecutive - start new group
                            groups.push(currentGroup);
                            currentGroup = [sortedIds[i]];
                        }
                    }
                    groups.push(currentGroup);

                    return groups;
                },

                combineSegmentsByIds(segmentIds) {
                    // Combine multiple consecutive segments into one using segment IDs
                    if (segmentIds.length === 1) {
                        const segment = this.roadSegments.find(s => s.id === segmentIds[0]);
                        return {
                            ids: segmentIds,
                            description: segment.description,
                            geometry: segment.geometry
                        };
                    }

                    // Merge geometries
                    let combinedGeometry = [];
                    for (const id of segmentIds) {
                        const segment = this.roadSegments.find(s => s.id === id);
                        if (segment) {
                            combinedGeometry = combinedGeometry.concat(segment.geometry);
                        }
                    }

                    // Remove duplicate points at boundaries
                    const uniqueGeometry = [];
                    for (let i = 0; i < combinedGeometry.length; i++) {
                        if (i === 0 ||
                            combinedGeometry[i][0] !== combinedGeometry[i-1][0] ||
                            combinedGeometry[i][1] !== combinedGeometry[i-1][1]) {
                            uniqueGeometry.push(combinedGeometry[i]);
                        }
                    }

                    // Create combined description
                    const firstSegment = this.roadSegments.find(s => s.id === segmentIds[0]);
                    const lastSegment = this.roadSegments.find(s => s.id === segmentIds[segmentIds.length - 1]);

                    // Extract street names from descriptions
                    let description = '';
                    if (firstSegment && lastSegment) {
                        const firstDesc = firstSegment.description;
                        const lastDesc = lastSegment.description;

                        if (firstDesc.includes('To ')) {
                            // First segment starts from beginning
                            const toStreet = lastDesc.match(/to (.+)$/);
                            description = toStreet ? `To ${toStreet[1]}` : `${segmentIds.length} segments`;
                        } else {
                            const fromStreet = firstDesc.match(/From (.+?)(?: to|$)/);
                            const toMatch = lastDesc.match(/to (.+)$/);

                            if (fromStreet && toMatch) {
                                description = `From ${fromStreet[1]} to ${toMatch[1]}`;
                            } else if (fromStreet) {
                                description = `From ${fromStreet[1]}`;
                            } else {
                                description = `${segmentIds.length} segments`;
                            }
                        }
                    } else {
                        description = `${segmentIds.length} segments`;
                    }

                    return {
                        ids: segmentIds,
                        description: description,
                        geometry: uniqueGeometry
                    };
                },
                
                cancelSelection() {
                    this.clearSegmentLayers();
                    this.selectionMode = false;

                    // Re-render report segments for the road we were selecting
                    if (this.selectedRoad) {
                        const road = this.allRoads.find(r => r.id === this.selectedRoad.id);
                        if (road) {
                            this.renderReportSegments(road);
                        }
                    }

                    this.selectedRoad = null;
                    this.roadSegments = [];
                    this.selectedSegments = [];
                    this.segmentIndexMap = [];
                    this.hasExistingReports = false;
                },
                
                getSegmentColor(index) {
                    const colors = ['#2563eb', '#d97706', '#7c3aed', '#059669'];
                    return colors[index % colors.length];
                },


                clearSegmentLayers() {
                    // First, remove ALL event handlers for ALL possible segment layers
                    // This ensures we catch any orphaned handlers
                    for (let i = 0; i < 10; i++) {  // Assume max 10 segments
                        const layerId = `segment-${i}`;
                        this.map.off('click', layerId);
                        this.map.off('mouseenter', layerId);
                        this.map.off('mouseleave', layerId);
                        this.map.off('mousedown', layerId);
                        this.map.off('mouseup', layerId);
                    }

                    // Now remove the layers and sources
                    this.segmentLayers.forEach(layerId => {
                        if (this.map.getLayer(layerId)) this.map.removeLayer(layerId);
                        const sourceId = `${layerId.replace('segment-', 'segment-source-')}`;
                        if (this.map.getSource(sourceId)) this.map.removeSource(sourceId);
                        // Also check for highlight-entire-road
                        if (layerId === 'highlight-entire-road') {
                            const highlightSource = 'highlight-entire-road-source';
                            if (this.map.getSource(highlightSource)) this.map.removeSource(highlightSource);
                        }
                    });
                    this.segmentLayers = [];
                },

                selectStatus(statusValue) {
                    const wasBlocked = this.isBlockedStatus;
                    this.newReport.status = statusValue;
                    const isBlocked = this.isBlockedStatus;

                    // Auto-populate coordinates when switching TO a blocked status with empty notes
                    if (isBlocked && !wasBlocked && !this.newReport.notes && this.reportClickLngLat) {
                        const lat = this.reportClickLngLat.lat.toFixed(6);
                        const lng = this.reportClickLngLat.lng.toFixed(6);
                        this.newReport.notes = `Location: ${lat}, ${lng}`;
                    }
                },

                updateLocationInNotes(lat, lng) {
                    const line = `Location: ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                    if (/^Location: /m.test(this.newReport.notes)) {
                        this.newReport.notes = this.newReport.notes.replace(/^Location: .*$/m, line);
                    } else {
                        this.newReport.notes = this.newReport.notes
                            ? `${line}\n${this.newReport.notes}`
                            : line;
                    }
                    // Re-show the modal if it was hidden for map picking on mobile
                    if (this._locationPickHidModal) {
                        this.showReportModal = true;
                        this._locationPickHidModal = false;
                    }
                },

                startLocationPick() {
                    this.locationPickMode = true;
                    this.map.getCanvas().style.cursor = 'crosshair';
                    // On mobile, hide the modal so the map is visible for tapping
                    if (this.showReportModal) {
                        this.showReportModal = false;
                        this._locationPickHidModal = true;
                    }
                },

                cancelLocationPick() {
                    this.locationPickMode = false;
                    this.map.getCanvas().style.cursor = '';
                    // Restore the modal if it was hidden
                    if (this._locationPickHidModal) {
                        this.showReportModal = true;
                        this._locationPickHidModal = false;
                    }
                },

                useGpsLocation() {
                    if (!navigator.geolocation) {
                        alert('Geolocation is not supported by your browser.');
                        return;
                    }
                    navigator.geolocation.getCurrentPosition(
                        (pos) => {
                            this.updateLocationInNotes(pos.coords.latitude, pos.coords.longitude);
                        },
                        () => {
                            alert('Could not get your location. Please enter coordinates manually or click the map.');
                        },
                        { timeout: 10000, enableHighAccuracy: true }
                    );
                },

                // Parse notes into parts — location line becomes an interactive button
                parseNotesParts(notes) {
                    if (!notes) return [];
                    const match = notes.match(/^(Location:\s*([-\d.]+),\s*([-\d.]+))([\s\S]*)$/m);
                    if (!match) return [{ type: 'text', text: notes }];
                    const parts = [];
                    parts.push({ type: 'location', display: match[1], lat: parseFloat(match[2]), lng: parseFloat(match[3]) });
                    if (match[4]) parts.push({ type: 'text', text: match[4] });
                    return parts;
                },

                handleLocationClick(lat, lng) {
                    if (this.isMobile) {
                        // geo: URI lets the OS prompt the user to open in their default map app
                        window.open(`geo:${lat},${lng}?q=${lat},${lng}`, '_blank');
                    } else {
                        // Desktop: fly to location and drop a pin
                        this.flyToLocation(lat, lng);
                    }
                },

                flyToLocation(lat, lng) {
                    // Remove any existing location marker
                    if (this.locationMarker) {
                        this.locationMarker.remove();
                        this.locationMarker = null;
                    }
                    // Add a red pin marker
                    this.locationMarker = new maplibregl.Marker({ color: '#dc2626' })
                        .setLngLat([lng, lat])
                        .addTo(this.map);
                    this.map.flyTo({ center: [lng, lat], zoom: Math.max(this.map.getZoom(), 15) });
                },

                cancelSelection() {
                    this.clearSegmentLayers();
                    this.selectionMode = false;

                    // Re-render report segments for the road we were selecting
                    if (this.selectedRoad) {
                        const road = this.allRoads.find(r => r.id === this.selectedRoad.id);
                        if (road) {
                            this.renderReportSegments(road);
                        }
                    }

                    this.selectedRoad = null;
                    this.roadSegments = [];
                },

                closeModal() {
                    this.showReportModal = false;

                    // Re-render report segments if we were working on a road
                    if (this.selectedRoad) {
                        const road = this.allRoads.find(r => r.id === this.selectedRoad.id);
                        if (road) {
                            this.renderReportSegments(road);
                        }
                    }

                    this.selectedRoad = null;
                    this.roadSegments = [];
                    this.clearSegmentLayers();
                },

                cancelSidebarReport() {
                    this.showSidebarReportForm = false;
                    this.activeTab = 'reports';

                    // Re-render report segments if we were working on a road
                    if (this.selectedRoad) {
                        const road = this.allRoads.find(r => r.id === this.selectedRoad.id);
                        if (road) {
                            this.renderReportSegments(road);
                        }
                    }

                    this.selectedRoad = null;
                    this.roadSegments = [];
                    this.clearSegmentLayers();
                },

                async submitMultipleReports() {
                    // Submit multiple reports for non-consecutive segment groups
                    const reportingRoad = this.selectedRoad;
                    const roadName = reportingRoad.name || 'Unnamed Road';
                    const status = this.newReport.status;
                    const notes = this.newReport.notes;

                    // Validate notes for inappropriate content
                    if (notes) {
                        const validation = this.validateNotes(notes);
                        if (!validation.valid) {
                            alert(validation.message);
                            return;
                        }
                    }

                    const reports = this.newReport.segmentGroups.map(group => {
                        const report = {
                            road_id: reportingRoad.id,
                            road_name: roadName,
                            segment: 'combined',
                            segment_description: group.description,
                            geometry: group.geometry,
                            status: status,
                            notes: notes,
                            timestamp: new Date().toISOString()
                        };

                        // Add segmentIds array (now using unique segment IDs)
                        if (group.ids) {
                            report.segmentIds = group.ids;
                        }

                        return report;
                    });

                    try {
                        // Submit all reports
                        const submissions = reports.map(report =>
                            fetch('api.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                                    'Pragma': 'no-cache'
                                },
                                body: JSON.stringify({
                                    action: 'add_report',
                                    report: report
                                })
                            }).then(r => r.json())
                        );

                        const results = await Promise.all(submissions);

                        // Check if all succeeded
                        const allSuccess = results.every(data => data.success);

                        if (allSuccess) {
                            // Show flash animations for all segments
                            const flashColor = this.getStatusColor(status);
                            reports.forEach((report, idx) => {
                                setTimeout(() => {
                                    const layerId = `flash-${Date.now()}-${idx}`;
                                    const sourceId = `flash-source-${Date.now()}-${idx}`;

                                    const geojson = {
                                        type: 'Feature',
                                        geometry: {
                                            type: 'LineString',
                                            coordinates: report.geometry.map(coord => [coord[1], coord[0]])
                                        }
                                    };

                                    this.map.addSource(sourceId, {
                                        type: 'geojson',
                                        data: geojson
                                    });

                                    this.map.addLayer({
                                        id: layerId,
                                        type: 'line',
                                        source: sourceId,
                                        paint: {
                                            'line-color': flashColor,
                                            'line-width': 12,
                                            'line-opacity': 1
                                        }
                                    });

                                    // Animate the flash
                                    setTimeout(() => this.map.setPaintProperty(layerId, 'line-opacity', 0.7), 200);
                                    setTimeout(() => this.map.setPaintProperty(layerId, 'line-opacity', 1), 400);
                                    setTimeout(() => {
                                        if (this.map.getLayer(layerId)) this.map.removeLayer(layerId);
                                        if (this.map.getSource(sourceId)) this.map.removeSource(sourceId);
                                    }, 800);
                                }, idx * 100); // Stagger the flashes
                            });

                            this.closeModal();
                            this.showSidebarReportForm = false;
                            this.activeTab = 'reports';

                            // Optimistically add reports from server responses
                            results.forEach(data => {
                                if (data.success && data.report) {
                                    const existing = this.reports.find(r => r.id === data.report.id);
                                    if (!existing) {
                                        this.reports.push(data.report);
                                    }
                                }
                            });

                            // Re-render road segments (SSE delta will also handle this)
                            const road = this.allRoads.find(r => r.id === reportingRoad.id);
                            if (road && !(this.selectionMode && this.selectedRoad && this.selectedRoad.id === reportingRoad.id)) {
                                this.renderReportSegments(road);
                            }
                        } else {
                            const failedCount = results.filter(data => !data.success).length;
                            alert(`Error: ${failedCount} of ${reports.length} reports failed to submit.`);
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        alert('Error submitting reports. Please try again.');
                    }
                },
                
                async submitReport() {
                    if (!this.newReport.status || !this.selectedRoad) return;

                    // Store road reference before any clearing happens
                    const reportingRoad = this.selectedRoad;
                    const roadName = reportingRoad.name || 'Unnamed Road';

                    // Handle multiple non-consecutive segments - create separate reports
                    if (this.newReport.segment === 'multiple') {
                        await this.submitMultipleReports();
                        return;
                    }

                    let segmentDescription = '';
                    let geometry = reportingRoad.geometry;
                    let segmentIds = null;

                    if (this.newReport.segment === 'entire') {
                        segmentDescription = 'Entire road';
                    } else if (this.newReport.segment === 'single') {
                        // Single segment using unique ID
                        const segment = this.newReport.singleSegment;
                        segmentDescription = segment.description;
                        geometry = segment.geometry;
                        segmentIds = [segment.id];
                    } else if (this.newReport.segment === 'combined') {
                        // Combined consecutive segments
                        segmentDescription = this.newReport.combinedSegment.description;
                        geometry = this.newReport.combinedSegment.geometry;
                        segmentIds = this.newReport.combinedSegment.ids;
                    }

                    // Check for duplicate/conflicting reports (check ALL reports, including "clear")
                    const roadReports = this.reports.filter(r => r.road_id === reportingRoad.id);

                    if (this.newReport.segment === 'entire') {
                        // If reporting entire road, check if ANY segment already has a report
                        if (roadReports.length > 0) {
                            alert(`This road already has existing reports on specific segments. Please report individual segments instead.`);
                            return;
                        }
                    } else if (segmentIds) {
                        // Check if any of these segment IDs already have reports
                        const conflictingReport = roadReports.find(r => {
                            if (r.segment === 'entire') return true;
                            if (r.segmentIds) {
                                // Check if any segment ID overlaps
                                return r.segmentIds.some(id => segmentIds.includes(id));
                            }
                            return false;
                        });

                        if (conflictingReport) {
                            alert(`One or more of these segments already have reports. Please update or delete the existing report first.`);
                            return;
                        }
                    }

                    // Validate notes for inappropriate content
                    if (this.newReport.notes) {
                        const validation = this.validateNotes(this.newReport.notes);
                        if (!validation.valid) {
                            alert(validation.message);
                            return;
                        }
                    }

                    const report = {
                        road_id: reportingRoad.id,
                        road_name: roadName,
                        segment: this.newReport.segment,
                        segment_description: segmentDescription,
                        geometry: geometry,
                        status: this.newReport.status,
                        notes: this.newReport.notes,
                        timestamp: new Date().toISOString()
                    };

                    // Add segmentIds array for single and combined segments
                    if (segmentIds) {
                        report.segmentIds = segmentIds;
                    }

                    try {
                        const response = await fetch('api.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Cache-Control': 'no-cache, no-store, must-revalidate',
                                'Pragma': 'no-cache'
                            },
                            body: JSON.stringify({
                                action: 'add_report',
                                report: report
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            // Optimistically add the report to local state immediately
                            // Use the report object from the server response (has server-assigned ID)
                            const newReport = data.report || { ...report, id: `report_${Date.now()}` };
                            this.reports.push(newReport);

                            // Show immediate visual feedback - flash the segment
                            const flashColor = this.getStatusColor(this.newReport.status);
                            const layerId = `flash-${Date.now()}`;
                            const sourceId = `flash-source-${Date.now()}`;

                            const geojson = {
                                type: 'Feature',
                                geometry: {
                                    type: 'LineString',
                                    coordinates: geometry.map(coord => [coord[1], coord[0]])
                                }
                            };

                            this.map.addSource(sourceId, {
                                type: 'geojson',
                                data: geojson
                            });

                            this.map.addLayer({
                                id: layerId,
                                type: 'line',
                                source: sourceId,
                                paint: {
                                    'line-color': flashColor,
                                    'line-width': 12,
                                    'line-opacity': 1
                                }
                            });

                            // Animate the flash
                            setTimeout(() => {
                                this.map.setPaintProperty(layerId, 'line-opacity', 0.7);
                            }, 200);
                            setTimeout(() => {
                                this.map.setPaintProperty(layerId, 'line-opacity', 1);
                            }, 400);
                            setTimeout(() => {
                                if (this.map.getLayer(layerId)) this.map.removeLayer(layerId);
                                if (this.map.getSource(sourceId)) this.map.removeSource(sourceId);
                            }, 800);

                            this.closeModal();
                            this.showSidebarReportForm = false;
                            this.activeTab = 'reports';

                            // Add new segment overlay for this report
                            // BUT don't re-render if user has already entered selection mode for this road
                            const road = this.allRoads.find(r => r.id === reportingRoad.id);
                            if (road && !(this.selectionMode && this.selectedRoad && this.selectedRoad.id === reportingRoad.id)) {
                                // Re-render all segment overlays (including the new one)
                                this.renderReportSegments(road);
                            }
                        } else {
                            alert('Error submitting report: ' + (data.error || 'Unknown error'));
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        alert('Error submitting report. Please try again.');
                    }
                },
                
                connectSSE() {
                    // Close existing connection if any
                    if (this.eventSource) {
                        this.eventSource.close();
                    }

                    this.eventSource = new EventSource('sse.php');

                    this.eventSource.onopen = () => {
                    };

                    this.eventSource.onmessage = (event) => {
                        try {
                            const data = JSON.parse(event.data);

                            if (data.type === 'init') {
                                // Full report set on connection/reconnection
                                this.lastChangeId = data.lastChangeId || 0;
                                this.processReportsUpdate(data.reports);
                                // SSE init counts as reports loaded
                                this.initializationState.reportsLoaded = true;
                                this.checkInitializationComplete();
                            } else if (data.type === 'report_added') {
                                // Delta: single report added
                                const timeSinceInteraction = Date.now() - this.lastUserInteraction;
                                if (timeSinceInteraction > 4000) {
                                    this.handleReportAdded(data.report);
                                }
                                if (data.changeId) this.lastChangeId = data.changeId;
                            } else if (data.type === 'report_updated') {
                                // Delta: single report updated (e.g. via database admin)
                                const timeSinceInteraction = Date.now() - this.lastUserInteraction;
                                if (timeSinceInteraction > 4000) {
                                    this.handleReportUpdated(data.report);
                                }
                                if (data.changeId) this.lastChangeId = data.changeId;
                            } else if (data.type === 'report_deleted') {
                                // Delta: single report deleted
                                const timeSinceInteraction = Date.now() - this.lastUserInteraction;
                                if (timeSinceInteraction > 4000) {
                                    this.handleReportDeleted(data.reportId);
                                }
                                if (data.changeId) this.lastChangeId = data.changeId;
                            }
                        } catch (error) {
                            console.error('[SSE] Error parsing data:', error, 'Raw data:', event.data);
                        }
                    };

                    this.eventSource.onerror = (error) => {
                        console.error('[SSE] Connection error! ReadyState:', this.eventSource.readyState);
                        console.error('[SSE] Error details:', error);
                        this.eventSource.close();

                        // Reconnect after 5 seconds
                        if (this.sseReconnectTimeout) {
                            clearTimeout(this.sseReconnectTimeout);
                        }
                        this.sseReconnectTimeout = setTimeout(() => {
                            this.connectSSE();
                        }, 5000);
                    };
                },

                processReportsUpdate(newReports) {
                    // Process SSE update with the same logic as loadReports
                    try {
                        const oldReports = this.reports;

                        // Check if this is initial render (no layers exist yet)
                        const isInitialRender = Object.keys(this.reportSegmentLayers).length === 0;

                        // Create sets of report IDs for comparison
                        const oldReportIds = new Set(oldReports.map(r => r.id));
                        const newReportIds = new Set(newReports.map(r => r.id));

                        // Find deleted reports (in old but not in new)
                        const deletedReportIds = [...oldReportIds].filter(id => !newReportIds.has(id));

                        // Find roads affected by deletions
                        const roadsNeedingUpdate = new Set();
                        deletedReportIds.forEach(reportId => {
                            const report = oldReports.find(r => r.id === reportId);
                            if (report) {
                                roadsNeedingUpdate.add(report.road_id);
                            }
                        });

                        // Check for new or changed reports, collect new ones for notifications
                        const newReportsForNotification = [];
                        newReports.forEach(newReport => {
                            const oldReport = oldReports.find(r => r.id === newReport.id);
                            if (!oldReport) {
                                // Truly new report
                                roadsNeedingUpdate.add(newReport.road_id);
                                newReportsForNotification.push(newReport);
                            } else if (oldReport.status !== newReport.status) {
                                // Status changed on existing report
                                roadsNeedingUpdate.add(newReport.road_id);
                            }
                        });

                        // Send browser notifications for new reports (skip initial load)
                        if (oldReports.length > 0 && newReportsForNotification.length > 0) {
                            this.notifyNewReports(newReportsForNotification);
                        }

                        // Update the reports data
                        this.reports = newReports;

                        // Only update roads that have changes (if map and ALL roads are fully loaded)
                        if (this.map && this.initializationState.roadsLoaded && this.allRoads && this.allRoads.length > 0) {
                            // On initial render, render all roads with reports
                            // On subsequent renders, only render roads with changes
                            const roadsToRender = isInitialRender
                                ? new Set(newReports.map(r => r.road_id))
                                : roadsNeedingUpdate;

                            roadsToRender.forEach(roadId => {
                                // Skip the currently selected road if in selection mode
                                if (this.selectionMode && this.selectedRoad && roadId === this.selectedRoad.id) {
                                    return;
                                }

                                const road = this.allRoads.find(r => r.id === roadId);
                                if (road) {
                                    // Clear existing overlays for this road only
                                    Object.keys(this.reportSegmentLayers).forEach(key => {
                                        if (key.startsWith(`report-segment-${roadId}-`)) {
                                            this.map.off('click', key);
                                            this.map.off('mouseenter', key);
                                            this.map.off('mouseleave', key);

                                            if (this.map.getLayer(key)) this.map.removeLayer(key);
                                            // Only remove source for main layers, not click layers
                                            if (!key.endsWith('-click') && this.map.getSource(key)) {
                                                this.map.removeSource(key);
                                            }
                                            delete this.reportSegmentLayers[key];
                                        }
                                    });

                                    // Re-render this road's report segments
                                    this.renderReportSegments(road);
                                }
                            });
                        }
                    } catch (error) {
                        console.error('Error processing SSE update:', error);
                    }
                },

                handleReportAdded(report) {
                    // Check if we already have this report (e.g. from optimistic add)
                    const existingIdx = this.reports.findIndex(r => r.id === report.id);
                    if (existingIdx !== -1) {
                        // Update in place if status changed
                        if (this.reports[existingIdx].status !== report.status) {
                            this.reports.splice(existingIdx, 1, report);
                        }
                        return;
                    }

                    // Add to reports array
                    this.reports.push(report);

                    // Notify if appropriate
                    this.notifyNewReports([report]);

                    // Re-render affected road on map
                    if (this.map && this.initializationState.roadsLoaded && this.allRoads && this.allRoads.length > 0) {
                        if (this.selectionMode && this.selectedRoad && report.road_id === this.selectedRoad.id) {
                            return;
                        }

                        const road = this.allRoads.find(r => r.id === report.road_id);
                        if (road) {
                            // Clear existing overlays for this road
                            Object.keys(this.reportSegmentLayers).forEach(key => {
                                if (key.startsWith(`report-segment-${report.road_id}-`)) {
                                    this.map.off('click', key);
                                    this.map.off('mouseenter', key);
                                    this.map.off('mouseleave', key);
                                    if (this.map.getLayer(key)) this.map.removeLayer(key);
                                    if (!key.endsWith('-click') && this.map.getSource(key)) this.map.removeSource(key);
                                    delete this.reportSegmentLayers[key];
                                }
                            });
                            this.renderReportSegments(road);
                        }
                    }
                },

                handleReportUpdated(report) {
                    const existingIdx = this.reports.findIndex(r => r.id === report.id);
                    if (existingIdx === -1) {
                        // Report doesn't exist locally, treat as add
                        this.handleReportAdded(report);
                        return;
                    }

                    // Replace in place
                    const oldRoadId = this.reports[existingIdx].road_id;
                    this.reports.splice(existingIdx, 1, report);

                    // Re-render affected road(s) on map
                    if (this.map && this.initializationState.roadsLoaded && this.allRoads && this.allRoads.length > 0) {
                        if (this.selectionMode && this.selectedRoad) return;

                        const roadIds = new Set([oldRoadId, report.road_id]);
                        for (const roadId of roadIds) {
                            const road = this.allRoads.find(r => r.id === roadId);
                            if (road) {
                                Object.keys(this.reportSegmentLayers).forEach(key => {
                                    if (key.startsWith(`report-segment-${roadId}-`)) {
                                        this.map.off('click', key);
                                        this.map.off('mouseenter', key);
                                        this.map.off('mouseleave', key);
                                        if (this.map.getLayer(key)) this.map.removeLayer(key);
                                        if (!key.endsWith('-click') && this.map.getSource(key)) this.map.removeSource(key);
                                        delete this.reportSegmentLayers[key];
                                    }
                                });
                                this.renderReportSegments(road);
                            }
                        }
                    }
                },

                handleReportDeleted(reportId) {
                    const report = this.reports.find(r => r.id === reportId);
                    if (!report) return;

                    const roadId = report.road_id;

                    // Remove from reports array
                    this.reports = this.reports.filter(r => r.id !== reportId);

                    // Re-render affected road on map
                    if (this.map && this.initializationState.roadsLoaded && this.allRoads && this.allRoads.length > 0) {
                        if (this.selectionMode && this.selectedRoad && roadId === this.selectedRoad.id) {
                            return;
                        }

                        const road = this.allRoads.find(r => r.id === roadId);
                        if (road) {
                            Object.keys(this.reportSegmentLayers).forEach(key => {
                                if (key.startsWith(`report-segment-${roadId}-`)) {
                                    this.map.off('click', key);
                                    this.map.off('mouseenter', key);
                                    this.map.off('mouseleave', key);
                                    if (this.map.getLayer(key)) this.map.removeLayer(key);
                                    if (!key.endsWith('-click') && this.map.getSource(key)) this.map.removeSource(key);
                                    delete this.reportSegmentLayers[key];
                                }
                            });
                            this.renderReportSegments(road);
                        }
                    }
                },

                // --- Notification Methods ---

                loadNotificationPreferences() {
                    if (!this.notificationsSupported) return;
                    try {
                        const saved = localStorage.getItem('roadStatusNotifications');
                        if (saved) {
                            const prefs = JSON.parse(saved);
                            this.notificationStatuses = prefs.statuses || ['blocked-tree', 'blocked-power'];
                            // Only restore enabled state if permission is still granted
                            if (Notification.permission === 'granted' && prefs.enabled) {
                                this.notificationsEnabled = true;
                            }
                        }
                        this.notificationPermission = Notification.permission;
                    } catch (e) {
                        console.error('Error loading notification preferences:', e);
                    }
                },

                saveNotificationPreferences() {
                    try {
                        localStorage.setItem('roadStatusNotifications', JSON.stringify({
                            enabled: this.notificationsEnabled,
                            statuses: this.notificationStatuses
                        }));
                    } catch (e) {
                        console.error('Error saving notification preferences:', e);
                    }
                },

                async toggleNotifications() {
                    if (!this.notificationsSupported) return;

                    if (this.notificationsEnabled) {
                        // Turn off
                        this.notificationsEnabled = false;
                        this.saveNotificationPreferences();
                        return;
                    }

                    // Need to request or check permission
                    if (Notification.permission === 'default') {
                        const result = await Notification.requestPermission();
                        this.notificationPermission = result;
                        if (result === 'granted') {
                            this.notificationsEnabled = true;
                            this.saveNotificationPreferences();
                        }
                    } else if (Notification.permission === 'granted') {
                        this.notificationsEnabled = true;
                        this.saveNotificationPreferences();
                    } else {
                        alert('Notifications are blocked. Please enable them in your browser settings for this site.');
                    }
                },

                toggleNotificationStatus(statusValue) {
                    const idx = this.notificationStatuses.indexOf(statusValue);
                    if (idx > -1) {
                        // Don't allow unchecking the last one
                        if (this.notificationStatuses.length <= 1) return;
                        this.notificationStatuses.splice(idx, 1);
                    } else {
                        this.notificationStatuses.push(statusValue);
                    }
                    this.saveNotificationPreferences();
                },

                notifyNewReports(newReports) {
                    if (!this.notificationsEnabled || !this.notificationsSupported) return;
                    if (Notification.permission !== 'granted') return;

                    // Filter to only statuses the user wants
                    const filtered = newReports.filter(r => this.notificationStatuses.includes(r.status));
                    if (filtered.length === 0) return;

                    if (!document.hidden && !this.isMobile) {
                        // Desktop with page visible: show in-page toast(s)
                        filtered.forEach(r => this.showToast(r));
                    } else {
                        // Background tab OR mobile: use HTML5 Notification
                        this.sendHtmlNotification(filtered);
                    }
                },

                showToast(report) {
                    const statusLabel = this.getStatusLabel(report.status);
                    const statusType = this.statusTypes.find(s => s.value === report.status);
                    const color = statusType ? statusType.color : '#9ca3af';
                    const id = Date.now() + Math.random();
                    this.toasts.push({ id, road_name: report.road_name, status_label: statusLabel, color, notes: report.notes || '' });
                    setTimeout(() => this.dismissToast(id), 8000);
                },

                dismissToast(id) {
                    const idx = this.toasts.findIndex(t => t.id === id);
                    if (idx !== -1) this.toasts.splice(idx, 1);
                },

                sendHtmlNotification(filtered) {
                    if (filtered.length === 1) {
                        const report = filtered[0];
                        const statusLabel = this.getStatusLabel(report.status);
                        let body = report.segment_description
                            ? `${statusLabel} - ${report.segment_description}`
                            : statusLabel;
                        if (report.notes) body += `\n${report.notes}`;
                        const n = new Notification(`New Report: ${report.road_name}`, { body, tag: report.id });
                        n.onclick = () => { window.focus(); n.close(); };
                    } else {
                        // Batch multiple reports into one notification
                        const roads = [...new Set(filtered.map(r => r.road_name))];
                        const body = roads.length <= 3
                            ? roads.join(', ')
                            : `${roads.slice(0, 3).join(', ')} and ${roads.length - 3} more`;
                        const n = new Notification(`${filtered.length} New Reports`, { body, tag: 'batch-' + Date.now() });
                        n.onclick = () => { window.focus(); n.close(); };
                    }
                },

                async loadReports() {
                    try {
                        // Use JSONL streaming for progressive loading
                        const timestamp = new Date().getTime();
                        const response = await fetch(`api.php?action=get_reports_stream&_=${timestamp}`, {
                            headers: {
                                'Cache-Control': 'no-cache, no-store, must-revalidate',
                                'Pragma': 'no-cache'
                            }
                        });

                        if (!response.ok) {
                            console.warn('Failed to load reports:', response.status);
                            return;
                        }

                        // Stream JSONL data
                        const reader = response.body.getReader();
                        const decoder = new TextDecoder();
                        let buffer = '';
                        const newReports = [];

                        while (true) {
                            const { done, value } = await reader.read();
                            if (done) break;

                            buffer += decoder.decode(value, { stream: true });
                            const lines = buffer.split('\n');
                            buffer = lines.pop(); // Keep incomplete line in buffer

                            for (const line of lines) {
                                if (line.trim()) {
                                    const report = JSON.parse(line);
                                    newReports.push(report);
                                }
                            }
                        }

                        // Process remaining buffer
                        if (buffer.trim()) {
                            const report = JSON.parse(buffer);
                            newReports.push(report);
                        }

                        // Now process the reports with the same logic as before
                        const oldReports = this.reports;

                        // Check if this is initial render (no layers exist yet)
                        const isInitialRender = Object.keys(this.reportSegmentLayers).length === 0;

                        // Create sets of report IDs for comparison
                        const oldReportIds = new Set(oldReports.map(r => r.id));
                        const newReportIds = new Set(newReports.map(r => r.id));

                        // Find deleted reports (in old but not in new)
                        const deletedReportIds = [...oldReportIds].filter(id => !newReportIds.has(id));

                        // Find roads affected by deletions
                        const roadsNeedingUpdate = new Set();
                        deletedReportIds.forEach(reportId => {
                            const report = oldReports.find(r => r.id === reportId);
                            if (report) {
                                roadsNeedingUpdate.add(report.road_id);
                            }
                        });

                        // Check for new or changed reports
                        newReports.forEach(newReport => {
                            const oldReport = oldReports.find(r => r.id === newReport.id);
                            if (!oldReport || oldReport.status !== newReport.status) {
                                // New report or status changed
                                roadsNeedingUpdate.add(newReport.road_id);
                            }
                        });

                        // Update the reports data
                        this.reports = newReports;

                        // Only update roads that have changes (if map and ALL roads are fully loaded)
                        if (this.map && this.initializationState.roadsLoaded && this.allRoads && this.allRoads.length > 0) {
                            // On initial render, render all roads with reports
                            // On subsequent renders, only render roads with changes
                            const roadsToRender = isInitialRender
                                ? new Set(newReports.map(r => r.road_id))
                                : roadsNeedingUpdate;

                            roadsToRender.forEach(roadId => {
                                // Skip the currently selected road if in selection mode
                                if (this.selectionMode && this.selectedRoad && roadId === this.selectedRoad.id) {
                                    return;
                                }

                                const road = this.allRoads.find(r => r.id === roadId);
                                if (road) {
                                    // Clear existing overlays for this road only
                                    Object.keys(this.reportSegmentLayers).forEach(key => {
                                        if (key.startsWith(`report-segment-${roadId}-`)) {
                                            this.map.off('click', key);
                                            this.map.off('mouseenter', key);
                                            this.map.off('mouseleave', key);

                                            if (this.map.getLayer(key)) this.map.removeLayer(key);
                                            // Only remove source for main layers, not click layers
                                            if (!key.endsWith('-click') && this.map.getSource(key)) {
                                                this.map.removeSource(key);
                                            }
                                            delete this.reportSegmentLayers[key];
                                        }
                                    });

                                    // Re-render this road's report segments
                                    this.renderReportSegments(road);
                                }
                            });
                        }

                        // Mark reports as loaded (even if failed - they're optional)
                        this.initializationState.reportsLoaded = true;
                        this.checkInitializationComplete();
                    } catch (error) {
                        // Silently fail - reports are optional and network errors are common
                        // Only log to console for debugging
                        console.debug('Reports unavailable:', error.message);

                        // Still mark as loaded even on error
                        this.initializationState.reportsLoaded = true;
                        this.checkInitializationComplete();
                    }
                },
                
                getRoadStatus(roadId, roadName) {
                    const roadReports = this.reports.filter(r => 
                        r.road_id == roadId || r.road_name === roadName
                    ).sort((a, b) => 
                        new Date(b.timestamp) - new Date(a.timestamp)
                    );
                    
                    if (roadReports.length === 0) return null;
                    return roadReports[0].status;
                },
                
                getStatusColor(status) {
                    const statusType = this.statusTypes.find(s => s.value === status);
                    return statusType ? statusType.color : '#9ca3af';
                },
                
                getStatusLabel(status) {
                    const statusType = this.statusTypes.find(s => s.value === status);
                    return statusType ? statusType.label : 'Unknown';
                },

                validateNotes(notes) {
                    if (!notes || notes.trim().length === 0) {
                        return { valid: true };
                    }

                    const text = notes.trim();

                    // Check length
                    if (text.length > 500) {
                        return {
                            valid: false,
                            message: 'Notes are too long (maximum 500 characters)'
                        };
                    }

                    // Check for inappropriate language patterns
                    const badPatterns = [
                        /\bf+[\W_]*u+[\W_]*c+[\W_]*k+/i,
                        /\bs+[\W_]*h+[\W_]*i+[\W_]*t+/i,
                        /\bb+[\W_]*i+[\W_]*t+[\W_]*c+[\W_]*h+/i,
                        /\ba+[\W_]*s+[\W_]*s+[\W_]*h+[\W_]*o+[\W_]*l+[\W_]*e+/i,
                        /\bd+[\W_]*a+[\W_]*m+[\W_]*n+/i,
                        /\bh+[\W_]*e+[\W_]*l+[\W_]*l+/i,
                        /\bc+[\W_]*r+[\W_]*a+[\W_]*p+/i,
                    ];

                    for (const pattern of badPatterns) {
                        if (pattern.test(text)) {
                            return {
                                valid: false,
                                message: 'Please keep comments appropriate and professional'
                            };
                        }
                    }

                    // Check for excessive caps (shouting)
                    const upperCount = (text.match(/[A-Z]/g) || []).length;
                    const totalLetters = (text.match(/[a-zA-Z]/g) || []).length;
                    if (totalLetters > 10 && upperCount / totalLetters > 0.7) {
                        return {
                            valid: false,
                            message: 'Please avoid excessive use of capital letters'
                        };
                    }

                    return { valid: true };
                },

                formatTime(timestamp) {
                    const date = new Date(timestamp);
                    const now = new Date();
                    const diff = now - date;
                    const minutes = Math.floor(diff / 60000);
                    const hours = Math.floor(minutes / 60);
                    const days = Math.floor(hours / 24);
                    
                    if (minutes < 1) return 'Just now';
                    if (minutes < 60) return `${minutes}m ago`;
                    if (hours < 24) return `${hours}h ago`;
                    if (days === 1) return 'Yesterday';
                    if (days < 7) return `${days}d ago`;
                    
                    return date.toLocaleDateString();
                },
                
                focusOnReport(report) {
                    if (report.geometry && report.geometry.length > 0) {
                        // Convert to LngLatBounds format for MapLibre
                        const coords = report.geometry.map(coord => [coord[1], coord[0]]);
                        const bounds = coords.reduce((bounds, coord) => {
                            return bounds.extend(coord);
                        }, new maplibregl.LngLatBounds(coords[0], coords[0]));

                        this.map.fitBounds(bounds, { padding: {top: 50, bottom: 50, left: 50, right: 50} });
                    }

                    // Don't auto-close sidebar - let user control it
                },
                
                searchRoads() {
                    if (this.searchQuery.length < 2) {
                        this.searchResults = [];
                        return;
                    }
                    
                    const query = this.searchQuery.toLowerCase();
                    const seenIds = new Set();
                    
                    this.searchResults = this.allRoads
                        .filter(road => {
                            if (!road.name || !road.name.toLowerCase().includes(query)) return false;
                            if (seenIds.has(road.id)) return false;
                            seenIds.add(road.id);
                            return true;
                        })
                        .slice(0, 20);
                },
                
                selectRoadFromSearch(road) {
                    this.searchQuery = '';
                    this.searchResults = [];
                    
                    if (road.geometry && road.geometry.length > 0) {
                        const coords = road.geometry.map(coord => [coord[1], coord[0]]);
                        const bounds = coords.reduce((bounds, coord) => {
                            return bounds.extend(coord);
                        }, new maplibregl.LngLatBounds(coords[0], coords[0]));

                        this.map.fitBounds(bounds, { padding: {top: 100, bottom: 100, left: 100, right: 100} });
                    }
                    
                    // Close sidebar on mobile after selection
                    if (window.innerWidth <= 768) {
                        this.sidebarOpen = false;
                    }
                },
                
                toggleSidebar() {
                    this.sidebarOpen = !this.sidebarOpen;
                },

                // Mobile menu methods
                toggleMobileMenu() {
                    this.mobileMenuOpen = !this.mobileMenuOpen;
                    if (this.mobileMenuOpen) {
                        // Reset submenus when opening
                        this.showMobileLegend = false;
                        this.showMobileInfo = false;
                    }
                },

                closeMobileMenu() {
                    this.mobileMenuOpen = false;
                    this.showMobileLegend = false;
                    this.showMobileInfo = false;
                },

                openRoadReports() {
                    this.sidebarOpen = true;
                    this.closeMobileMenu();
                },

                toggleMobileLegend() {
                    this.showMobileLegend = !this.showMobileLegend;
                    if (this.showMobileLegend) {
                        this.showMobileInfo = false;
                        this.showMobileNotifications = false;
                    }
                },

                toggleMobileInfo() {
                    this.showMobileInfo = !this.showMobileInfo;
                    if (this.showMobileInfo) {
                        this.showMobileLegend = false;
                        this.showMobileNotifications = false;
                    }
                },

                toggleMobileNotifications() {
                    this.showMobileNotifications = !this.showMobileNotifications;
                    if (this.showMobileNotifications) {
                        this.showMobileLegend = false;
                        this.showMobileInfo = false;
                    }
                },

                openAbout() {
                    this.showAboutModal = true;
                    this.closeMobileMenu();
                },

                openInstructions() {
                    this.showHelpModal = true;
                    this.closeMobileMenu();
                },

                openDisclaimer() {
                    this.showDisclaimerModal = true;
                    this.closeMobileMenu();
                }
            }
        });

        // Mount and expose globally for debugging
        const app = vueApp.mount('#app');
        window.app = app; // Expose for console debugging

        // Keep --app-height in sync with the actual visible area.
        // window.visualViewport accounts for the browser address bar on mobile
        // (100vh does not reliably do this on Firefox/Chrome for Android).
        function updateAppHeight() {
            const h = window.visualViewport ? window.visualViewport.height : window.innerHeight;
            document.documentElement.style.setProperty('--app-height', `${h}px`);
        }
        updateAppHeight();
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', updateAppHeight);
        }
        window.addEventListener('resize', updateAppHeight);
