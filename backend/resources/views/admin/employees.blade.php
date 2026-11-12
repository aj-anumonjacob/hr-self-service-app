@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4>Employee Management</h4>
    <div>
        <button class="btn btn-outline-dark btn-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="bi bi-file-earmark-arrow-up"></i> Import CSV
        </button>
        <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#createModal">
            <i class="bi bi-plus-lg"></i> Add Employee
        </button>
    </div>
</div>

<div class="card card-premium p-3">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="table-light">
                <tr>
                    <th>Emp Code</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Department</th>
                    <th>Default Site</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($employees as $emp)
                <tr>
                    <td class="fw-bold text-dark">{{ $emp->employee_number }}</td>
                    <td>{{ $emp->full_name }}</td>
                    <td>{{ $emp->email }}</td>
                    <td>{{ $emp->department ? $emp->department->name : 'N/A' }}</td>
                    <td>{{ $emp->defaultLocation ? $emp->defaultLocation->name : 'N/A' }}</td>
                    <td>
                        <span class="badge {{ $emp->status === 'active' ? 'bg-success' : 'bg-danger' }} badge-premium">
                            {{ ucfirst($emp->status) }}
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-outline-secondary btn-xs"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-outline-danger btn-xs"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<!-- Simple Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk Import CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form action="#" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Upload Employee CSV File</label>
                        <input type="file" class="form-control" name="file" required>
                    </div>
                    <button type="submit" class="btn btn-dark w-100">Upload & Sync</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
