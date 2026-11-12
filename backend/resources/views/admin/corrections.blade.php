@extends('layouts.admin')

@section('content')
<h4 class="mb-4">Attendance Correction Requests</h4>

<div class="card card-premium p-3">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Employee</th>
                    <th>Type</th>
                    <th>Requested Value</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($corrections as $corr)
                <tr>
                    <td>{{ $corr->date }}</td>
                    <td class="fw-bold">{{ $corr->employee->full_name }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $corr->correction_type)) }}</td>
                    <td>{{ $corr->requested_time }}</td>
                    <td>{{ $corr->reason }}</td>
                    <td>
                        <span class="badge {{ $corr->status === 'pending' ? 'bg-warning text-dark' : ($corr->status === 'approved' ? 'bg-success' : 'bg-danger') }} badge-premium">
                            {{ ucfirst($corr->status) }}
                        </span>
                    </td>
                    <td>
                        @if($corr->status === 'pending')
                        <form action="/admin/corrections/{{ $corr->uuid }}/approve" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-success btn-sm me-1"><i class="bi bi-check-lg"></i> Approve</button>
                        </form>
                        <form action="/admin/corrections/{{ $corr->uuid }}/reject" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-x-lg"></i> Reject</button>
                        </form>
                        @else
                        <span class="text-secondary small">Resolved</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
