@extends('layouts.admin')

@section('content')
<h4 class="mb-4">Attendance Timeline Logs</h4>

<div class="card card-premium p-3">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Employee</th>
                    <th>Check In</th>
                    <th>Check Out</th>
                    <th>Duration</th>
                    <th>Break Duration</th>
                    <th>Location Verification</th>
                    <th>Risk Flags</th>
                </tr>
            </thead>
            <tbody>
                @foreach($records as $rec)
                <tr>
                    <td class="fw-bold">{{ $rec->date }}</td>
                    <td>{{ $rec->employee->full_name }}</td>
                    <td>{{ $rec->checkInEvent ? $rec->checkInEvent->event_time_device : 'N/A' }}</td>
                    <td>{{ $rec->checkOutEvent ? $rec->checkOutEvent->event_time_device : 'N/A' }}</td>
                    <td>{{ $rec->net_duration_minutes }} mins</td>
                    <td>{{ $rec->break_duration_minutes }} mins</td>
                    <td>
                        <span class="badge bg-success-subtle text-success badge-premium">Verified Geofence</span>
                    </td>
                    <td>
                        <span class="badge {{ $rec->security_risk_level === 'low' ? 'bg-success' : 'bg-warning text-dark' }} badge-premium">
                            {{ ucfirst($rec->security_risk_level) }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
