@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4>Shift Schedules & Rules</h4>
    <button class="btn btn-dark btn-sm">
        <i class="bi bi-plus-lg"></i> Add Shift
    </button>
</div>

<div class="card card-premium p-3 mb-4">
    <h6 class="text-secondary mb-3">Shift Lists</h6>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Hours</th>
                    <th>Grace Period</th>
                    <th>Late Limit</th>
                    <th>Break Auto Deduct</th>
                </tr>
            </thead>
            <tbody>
                @foreach($shifts as $shift)
                <tr>
                    <td class="fw-bold">{{ $shift->code }}</td>
                    <td>{{ $shift->name }}</td>
                    <td>{{ $shift->start_time }} - {{ $shift->end_time }}</td>
                    <td>{{ $shift->grace_period_minutes }} mins</td>
                    <td>{{ $shift->late_threshold_minutes }} mins</td>
                    <td>
                        <span class="badge bg-success-subtle text-success badge-premium">Enabled</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
