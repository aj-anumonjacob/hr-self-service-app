@extends('layouts.admin')

@section('content')
<div class="row g-4 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card card-premium card-gold-accent p-3">
            <div class="text-muted small">Total Team Members</div>
            <div class="fs-2 fw-bold text-dark">{{ $stats['team_size'] }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card card-premium p-3">
            <div class="text-muted small text-success">Present Today</div>
            <div class="fs-2 fw-bold text-success">{{ $stats['present_today'] }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card card-premium p-3">
            <div class="text-muted small text-warning">Late Arrivals</div>
            <div class="fs-2 fw-bold text-warning">{{ $stats['late_today'] }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card card-premium p-3">
            <div class="text-muted small text-danger">Pending Corrections</div>
            <div class="fs-2 fw-bold text-danger">{{ $stats['pending_corrections'] }}</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Live Map -->
    <div class="col-lg-8">
        <div class="card card-premium p-3 h-100">
            <h6 class="card-title text-secondary"><i class="bi bi-map"></i> Active Office Geofence Map</h6>
            <div id="dashboardMap" style="height: 400px; border-radius: 8px;"></div>
        </div>
    </div>

    <!-- Live Status Tracker -->
    <div class="col-lg-4">
        <div class="card card-premium p-3 h-100">
            <h6 class="card-title text-secondary"><i class="bi bi-activity"></i> Active Sessions</h6>
            <div class="list-group list-group-flush mt-2">
                @forelse($activeSessions as $session)
                <div class="list-group-item px-0 py-3">
                    <div class="d-flex w-100 justify-content-between align-items-center">
                        <h6 class="mb-1 fw-bold text-dark">{{ $session->employee->full_name }}</h6>
                        <span class="badge bg-success-subtle text-success badge-premium">Checked In</span>
                    </div>
                    <p class="mb-1 text-muted small"><i class="bi bi-geo-alt"></i> {{ $session->location->name }}</p>
                    <small class="text-secondary"><i class="bi bi-clock"></i> Time: {{ $session->checkInEvent ? $session->checkInEvent->event_time_device : 'N/A' }}</small>
                </div>
                @empty
                <div class="text-center py-4 text-secondary">
                    <i class="bi bi-inbox fs-2"></i>
                    <p class="mt-2">No active sessions at this moment.</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Initialize Map in Downtown Dubai
        var map = L.map('dashboardMap').setView([25.2048, 55.2708], 13);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        // Geofences coordinates mapped dynamically
        @foreach($locations as $loc)
            L.marker([{{ $loc->latitude }}, {{ $loc->longitude }}]).addTo(map)
                .bindPopup("<b>{{ $loc->name }}</b><br>Geofence radius: {{ $loc->check_in_radius }}m")
                .openPopup();
            L.circle([{{ $loc->latitude }}, {{ $loc->longitude }}], {
                color: '#c5a059',
                fillColor: '#dfc187',
                fillOpacity: 0.3,
                radius: {{ $loc->check_in_radius }}
            }).addTo(map);
        @endforeach
    });
</script>
@endsection
