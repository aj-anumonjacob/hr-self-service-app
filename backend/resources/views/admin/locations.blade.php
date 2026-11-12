@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4>Office Locations & Geofences</h4>
    <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#locationModal">
        <i class="bi bi-plus-lg"></i> Add Location
    </button>
</div>

<div class="row g-4">
    <!-- List -->
    <div class="col-lg-5">
        <div class="card card-premium p-3">
            <div class="list-group list-group-flush">
                @foreach($locations as $loc)
                <div class="list-group-item px-0 py-3">
                    <div class="d-flex justify-content-between">
                        <h6 class="mb-1 fw-bold text-dark">{{ $loc->name }}</h6>
                        <span class="badge bg-dark-subtle text-dark badge-premium">{{ $loc->code }}</span>
                    </div>
                    <p class="text-secondary small mb-1">{{ $loc->address }}</p>
                    <small class="text-muted">Radius: {{ $loc->check_in_radius }}m | Face Policy: {{ ucfirst($loc->face_requirement) }}</small>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Map Coordinate Picker -->
    <div class="col-lg-7">
        <div class="card card-premium p-3">
            <h6 class="mb-3"><i class="bi bi-geo"></i> Geofence Editor & Preview</h6>
            <div id="pickerMap" style="height: 400px; border-radius: 8px;"></div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener("DOMContentLoaded", function() {
        var map = L.map('pickerMap').setView([25.2048, 55.2708], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        // Add draggable marker to pick location
        var pickerMarker = L.marker([25.2048, 55.2708], { draggable: true }).addTo(map)
            .bindPopup("Drag me to pick coordinate values")
            .openPopup();

        var pickerCircle = L.circle([25.2048, 55.2708], {
            color: '#c5a059',
            radius: 100
        }).addTo(map);

        pickerMarker.on('dragend', function(event) {
            var position = pickerMarker.getLatLng();
            pickerCircle.setLatLng(position);
            console.log("Selected Location: Latitude: " + position.lat + ", Longitude: " + position.lng);
        });
    });
</script>
@endsection
