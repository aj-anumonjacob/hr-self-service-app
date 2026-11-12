@extends('layouts.admin')

@section('content')
<h4 class="mb-4">Audit Logs & Security Reviews</h4>

<div class="card card-premium p-3">
    <div class="table-responsive">
        <table class="table text-secondary">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Target Event</th>
                    <th>IP Address</th>
                    <th>User Agent</th>
                </tr>
            </thead>
            <tbody>
                @foreach($logs as $log)
                <tr>
                    <td>{{ $log->created_at }}</td>
                    <td class="fw-bold text-dark">{{ $log->user ? $log->user->name : 'System' }}</td>
                    <td>{{ $log->action }}</td>
                    <td>{{ $log->model_type }} (ID: {{ $log->model_id }})</td>
                    <td>{{ $log->ip_address }}</td>
                    <td class="small">{{ $log->user_agent }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
