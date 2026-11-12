<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demo Company - Antigravity Attendance Portal</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <style>
        :root {
            --dark-gold: #c5a059;
            --light-gold: #dfc187;
            --charcoal: #121212;
            --dark-gray: #1e1e1e;
            --soft-white: #f8f9fa;
        }
        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--soft-white);
            color: #333;
        }
        .sidebar {
            background-color: var(--charcoal);
            min-height: 100vh;
            color: white;
            border-right: 3px solid var(--dark-gold);
        }
        .sidebar-brand {
            font-size: 1.25rem;
            font-weight: bold;
            padding: 1.5rem 1rem;
            color: var(--light-gold);
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid #2d2d2d;
        }
        .sidebar-brand img {
            max-height: 40px;
            margin-right: 10px;
        }
        .nav-link {
            color: #ccc;
            padding: 0.8rem 1.2rem;
            display: flex;
            align-items: center;
            border-radius: 4px;
            margin: 0.2rem 0.5rem;
            transition: all 0.2s ease;
        }
        .nav-link i {
            margin-right: 10px;
            font-size: 1.1rem;
        }
        .nav-link:hover, .nav-link.active {
            background-color: var(--dark-gold);
            color: var(--charcoal) !important;
            font-weight: 600;
        }
        .navbar-custom {
            background-color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-bottom: 1px solid #e9ecef;
            padding: 1rem;
        }
        .card-premium {
            border: none;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            transition: transform 0.2s;
            background-color: white;
        }
        .card-premium:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.05);
        }
        .card-gold-accent {
            border-top: 4px solid var(--dark-gold);
        }
        .badge-premium {
            font-weight: 500;
            padding: 0.4em 0.8em;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0 sidebar d-none d-md-block">
                <div class="sidebar-brand d-flex align-items-center justify-content-center">
                    <span>Demo Company</span>
                </div>
                <ul class="nav flex-column mt-3">
                    <li class="nav-item">
                        <a class="nav-link {{ Request::is('admin/dashboard') ? 'active' : '' }}" href="/admin/dashboard">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ Request::is('admin/employees*') ? 'active' : '' }}" href="/admin/employees">
                            <i class="bi bi-people"></i> Employees
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ Request::is('admin/locations*') ? 'active' : '' }}" href="/admin/locations">
                            <i class="bi bi-geo-alt"></i> Locations
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ Request::is('admin/shifts*') ? 'active' : '' }}" href="/admin/shifts">
                            <i class="bi bi-clock-history"></i> Shifts
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ Request::is('admin/attendance*') ? 'active' : '' }}" href="/admin/attendance">
                            <i class="bi bi-calendar-check"></i> Attendance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ Request::is('admin/corrections*') ? 'active' : '' }}" href="/admin/corrections">
                            <i class="bi bi-pencil-square"></i> Corrections
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ Request::is('admin/audit*') ? 'active' : '' }}" href="/admin/audit">
                            <i class="bi bi-shield-lock"></i> Audit & Security
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Content Area -->
            <div class="col-md-9 col-lg-10 px-0">
                <!-- Top Navbar -->
                <div class="navbar-custom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-secondary">Antigravity Attendance Console</h5>
                    <div class="dropdown">
                        <button class="btn btn-outline-dark dropdown-toggle btn-sm" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> Administrator
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#"><i class="bi bi-gear"></i> Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="/"><i class="bi bi-box-arrow-right"></i> Log out</a></li>
                        </ul>
                    </div>
                </div>

                <!-- Page Content -->
                <div class="p-4">
                    @yield('content')
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap & Leaflet JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    @yield('scripts')
</body>
</html>
