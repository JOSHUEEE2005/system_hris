@props(['title' => 'Super Admin'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | GPCCI HRIS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @view-transition{navigation:auto}
        .sa-shell{min-height:100vh;background:#e7ebef;color:#142c4e}
        .sa-top{height:64px;background:#fff;box-shadow:0 2px 12px #142c4e0d}
        .sa-logo{display:block;width:60px;height:52px;object-fit:contain}
        .sa-side{position:fixed;inset:0 auto 0 0;z-index:60;display:flex;width:84px;height:100vh;min-height:0;flex-direction:column;gap:10px;overflow-x:hidden;overflow-y:auto;padding:16px 9px;background:#142c4e;border-radius:0;box-shadow:4px 0 18px #142c4e14;view-transition-name:admin-sidebar;transition:width .28s cubic-bezier(.2,.8,.2,1)}
        .sa-side:hover,.sa-side:focus-within,html.sa-nav-expanded .sa-side{width:178px}
        .sa-brand{display:flex;height:56px;flex:none;align-items:center;justify-content:center;margin:0 0 12px}
        .sa-brand img{width:54px;height:52px;object-fit:contain;transition:width .28s cubic-bezier(.2,.8,.2,1)}
        .sa-side:hover .sa-brand,.sa-side:focus-within .sa-brand,html.sa-nav-expanded .sa-side .sa-brand{justify-content:flex-start;padding-left:4px}
        .sa-side:hover .sa-brand img,.sa-side:focus-within .sa-brand img,html.sa-nav-expanded .sa-side .sa-brand img{width:68px}
        .sa-side a:not(.sa-brand){display:flex;height:54px;min-width:0;flex:none;align-items:center;justify-content:center;gap:12px;padding:10px;border-radius:14px;color:#fff;font-size:14px;font-weight:700;white-space:nowrap;transition:background .18s ease,color .18s ease}
        .sa-side:hover a:not(.sa-brand),.sa-side:focus-within a:not(.sa-brand),html.sa-nav-expanded .sa-side a:not(.sa-brand){justify-content:flex-start;padding:10px 14px}
        .sa-side a:hover,.sa-side a.active{background:#fff;color:#142c4e}
        .sa-icon{flex:0 0 22px;width:22px;text-align:center;font-size:21px}
        .sa-nav-label{width:0;overflow:hidden;opacity:0;transition:width .28s cubic-bezier(.2,.8,.2,1),opacity .2s ease}
        .sa-side:hover .sa-nav-label,.sa-side:focus-within .sa-nav-label,html.sa-nav-expanded .sa-side .sa-nav-label{width:125px;opacity:1}
        .sa-app{min-height:100vh;margin-left:84px;transition:margin-left .28s cubic-bezier(.2,.8,.2,1)}
        .sa-side:hover+.sa-app,.sa-side:focus-within+.sa-app,html.sa-nav-expanded .sa-side+.sa-app{margin-left:178px}
        .sa-top{position:relative;z-index:40}
        .sa-top-inner{display:flex;width:100%;height:100%;align-items:center;justify-content:space-between;padding:0 20px}
        .sa-breadcrumb{display:flex;min-width:0;align-items:center;gap:10px;color:#818b93;font-size:13px;font-weight:600}
        .sa-breadcrumb strong{overflow:hidden;color:#162a3d;text-overflow:ellipsis;white-space:nowrap}
        .sa-breadcrumb-separator{color:#a7afb5}
        .sa-top-actions{display:flex;flex:none;align-items:center;gap:8px}
        .sa-header-icon-button{width:38px;height:38px;border:1px solid transparent;border-radius:9px;color:#142c4e}
        .sa-header-icon-button:hover,.sa-header-icon-button[aria-expanded=true]{border-color:#dbe3e9;background:#f0f3f5}
        .sa-header-icon-button:focus-visible{outline:2px solid #142c4e;outline-offset:2px}
        .sa-layout{display:block;width:auto;margin:clamp(14px,2vw,24px) clamp(12px,2vw,22px) 32px;padding:0}
        .sa-main{--sa-main-padding:clamp(18px,2.4vw,30px);width:100%;min-width:0;padding:var(--sa-main-padding);border-radius:14px;background:#e2e7ea;box-shadow:0 10px 32px #173b5916}
        .sa-head{display:flex;flex-direction:row;align-items:center;justify-content:space-between;min-height:70px;margin:calc(0px - var(--sa-main-padding)) calc(0px - var(--sa-main-padding)) clamp(18px,2vw,24px);padding:18px clamp(20px,2.8vw,32px);background:#142c4e;color:#fff;border-radius:14px 14px 0 0}
        .sa-head h1{margin:0;color:#fff;font-size:22px;letter-spacing:-.02em}
        .sa-head>strong{font-size:19px}
        .sa-card{padding:16px;border:1px solid #e8edf1;border-radius:12px;background:#fff;box-shadow:0 3px 12px #142c4e0d}
        .sa-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:clamp(12px,1.8vw,20px);margin-top:0}
        .sa-kpi{display:flex;align-items:center;gap:13px;min-height:78px;padding:14px;border-right:4px solid #08dca9}
        .sa-kpi:nth-child(2){border-right-color:#f1c400}
        .sa-kpi:nth-child(3){border-right-color:#347cf4}
        .sa-kpi:nth-child(4){border-right-color:#f43f50}
        .sa-kpi-icon{display:grid;width:50px;height:50px;flex:none;place-items:center;border-radius:50%;background:#142c4e;color:#fff;font-size:23px}
        .sa-kpi strong{display:block;color:#07558a;font-size:23px}
        .sa-kpi small{display:block;font-size:10px;font-weight:700}
        .sa-section-title{margin:0 0 14px;color:#07558a;font-size:14px;font-weight:800}
        .sa-table{width:100%;border-collapse:collapse;font-size:11px}
        .sa-table th{background:#142c4e;color:#fff;text-align:left}
        .sa-table th,.sa-table td{padding:9px 10px}
        .sa-table td{border-bottom:1px solid #c7d0d7;background:#e1e6e9}
        .sa-list{margin:0;padding:0;list-style:none;font-size:12px}
        .sa-list li{padding:9px 0;border-bottom:1px solid #e7ebef}
        .sa-grid,.sa-calendar-layout{display:grid;grid-template-columns:minmax(0,3fr) minmax(150px,1fr);gap:clamp(14px,2vw,22px);margin-top:clamp(16px,2vw,22px)}
        .sa-user-layout{display:grid;grid-template-columns:minmax(0,2.4fr) minmax(180px,.7fr);gap:16px;margin-top:20px}
        .sa-user-aside{display:flex;flex-direction:column;gap:16px}
        .sa-report-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .sa-report-grid div{border-radius:8px;background:#eef1f3;padding:12px;text-align:center;font-size:11px}
        .sa-report-grid strong{display:block;margin-top:5px;font-size:18px}
        .sa-calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border-left:1px solid #cbd5df;border-top:1px solid #cbd5df}
        .sa-calendar-grid>div{min-height:64px;border-right:1px solid #cbd5df;border-bottom:1px solid #cbd5df;background:#e1e6e9;padding:8px;font-size:10px}
        .sa-calendar-grid .weekday{min-height:auto;background:#dfe5e8;font-weight:700;text-align:center}
        .sa-muted{color:#7890a3;font-size:11px}
        .sa-directory-toolbar{display:grid;grid-template-columns:minmax(180px,1fr) repeat(3,auto);gap:8px;margin-bottom:16px}
        .sa-directory-toolbar input,.sa-directory-toolbar select{min-height:40px;border:1px solid #d6e0e7;border-radius:9px;background:#fff;padding:8px 11px;color:#315d78;font-size:12px}
        .sa-directory-groups{display:block}
        .sa-directory-group{overflow:hidden;margin-bottom:10px;border:1px solid #e1e7ec;border-radius:8px}
        .sa-directory-group .sa-group-title{display:flex;justify-content:space-between;background:#142c4e;padding:8px 11px;color:#fff;font-size:11px;font-weight:800}
        .sa-directory-group .sa-group-title span{opacity:.85}
        .sa-directory-group .sa-table{font-size:11px}
        .sa-directory-group .sa-table td{padding:6px 9px;background:#f7fafc}
        .sa-directory-empty{padding:8px 12px;color:#7890a3;background:#f7fafc;font-size:11px}
        .sa-badge{display:inline-block;border-radius:999px;padding:3px 7px;font-size:10px;font-weight:800}
        .sa-badge.active{background:#dcfce7;color:#166534}
        .sa-badge.pending{background:#fef3c7;color:#92400e}
        .sa-badge.disabled{background:#e2e8f0;color:#475569}
        .sa-table-scroll{min-width:0}
        @media(max-width:800px){
            .sa-side{position:relative;inset:auto;display:flex;width:auto;height:auto;min-height:0;flex-flow:row nowrap;align-items:center;gap:4px;overflow-x:auto;overflow-y:hidden;padding:7px 10px}
            .sa-side:hover,.sa-side:focus-within,html.sa-nav-expanded .sa-side{width:auto}
            .sa-brand{height:42px;width:46px;margin:0 6px 0 0}
            .sa-brand img{width:40px;height:40px}
            .sa-side:hover .sa-brand,.sa-side:focus-within .sa-brand{justify-content:center;padding-left:0}
            .sa-side:hover .sa-brand img,.sa-side:focus-within .sa-brand img{width:40px}
            .sa-side a:not(.sa-brand){height:42px;width:auto;justify-content:flex-start;padding:8px 10px}
            .sa-side:hover a:not(.sa-brand),.sa-side:focus-within a:not(.sa-brand){justify-content:flex-start;padding:8px 10px}
            .sa-nav-label,.sa-side:hover .sa-nav-label,.sa-side:focus-within .sa-nav-label{width:auto;overflow:visible;opacity:1}
            .sa-app,.sa-side:hover+.sa-app,.sa-side:focus-within+.sa-app,html.sa-nav-expanded .sa-side+.sa-app{margin-left:0}
            .sa-layout{margin:12px}
            .sa-main{padding:14px}
            .sa-head{margin:-14px -14px 14px;padding:16px}
            .sa-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
            .sa-grid,.sa-user-layout,.sa-calendar-layout{grid-template-columns:1fr}
            .sa-table-scroll,.sa-calendar-layout .sa-card{overflow-x:auto}
            .sa-table-scroll .sa-table{min-width:520px}
            .sa-calendar-grid{min-width:560px}
            .sa-directory-group{overflow-x:auto}
            .sa-directory-group .sa-table{min-width:580px}
        }
        @media(max-width:500px){
            .sa-top-inner{padding:0 12px}
            .sa-breadcrumb{gap:6px;font-size:11px}
            .sa-breadcrumb>span:first-child{max-width:38vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
            .sa-top-actions{gap:3px}
            .sa-head{gap:8px;flex-wrap:wrap}
            .sa-head h1{font-size:clamp(16px,5vw,20px)}
            .sa-head>strong{font-size:15px}
            .sa-kpis{gap:8px}
            .sa-kpi{padding:10px;gap:7px}
            .sa-kpi-icon{width:38px;height:38px;font-size:18px}
            .sa-report-grid{grid-template-columns:repeat(2,1fr)}
            .sa-directory-toolbar{grid-template-columns:1fr}
        }
        @media(prefers-reduced-motion:reduce){
            .sa-side,.sa-nav-label,.sa-app,.sa-brand img{transition:none}
            ::view-transition-old(admin-sidebar),::view-transition-new(admin-sidebar){animation:none}
        }
    </style>
    <script>
        if (sessionStorage.getItem('sa-nav-expanded') === '1') {
            document.documentElement.classList.add('sa-nav-expanded');
        }
    </script>
</head>
@php
    $viewer = auth()->user();
    $canAccessAdminHome = $viewer->hasPermission('users.manage')
        || $viewer->hasPermission('roles.manage')
        || $viewer->hasPermission('departments.manage')
        || $viewer->hasPermission('employees.manage')
        || $viewer->hasPermission('onboarding.review')
        || $viewer->hasPermission('audit.view');
@endphp
<body class="sa-shell">
    <nav class="sa-side" aria-label="{{ $canAccessAdminHome ? 'Administration' : 'Employee' }} navigation">
        <a class="sa-brand" href="{{ $canAccessAdminHome ? route('admin.dashboard') : route('stay-tuned') }}" aria-label="GPCCI HRIS home">
            <img class="sa-logo" src="{{ asset('images/gpcci-ahk-logo.png') }}" alt="AHK">
        </a>
        <a
            class="{{ ($canAccessAdminHome && request()->routeIs('admin.dashboard')) || (! $canAccessAdminHome && request()->routeIs('stay-tuned')) ? 'active' : '' }}"
            href="{{ $canAccessAdminHome ? route('admin.dashboard') : route('stay-tuned') }}"
        ><span class="sa-icon">⌂</span><span class="sa-nav-label">HOME</span></a>
        @if ($canAccessAdminHome)
            <a class="{{ request()->routeIs('admin.users') ? 'active' : '' }}" href="{{ route('admin.users') }}"><span class="sa-icon">♙</span><span class="sa-nav-label">User</span></a>
            <a class="{{ request()->routeIs('admin.calendar') ? 'active' : '' }}" href="{{ route('admin.calendar') }}"><span class="sa-icon">▣</span><span class="sa-nav-label">Calendar</span></a>
            <a class="{{ request()->routeIs('admin.contracts*') ? 'active' : '' }}" href="{{ route('admin.contracts') }}"><span class="sa-icon">♧</span><span class="sa-nav-label">Contracts</span></a>
            <a class="{{ request()->routeIs('admin.leaves') ? 'active' : '' }}" href="{{ route('admin.leaves') }}"><span class="sa-icon">♙</span><span class="sa-nav-label">Leaves</span></a>
            <a class="{{ request()->routeIs('admin.onboarding') ? 'active' : '' }}" href="{{ route('admin.onboarding') }}"><span class="sa-icon">☑</span><span class="sa-nav-label">Onboarding</span></a>
            @if ($viewer->hasPermission('audit.view'))
                <a class="{{ request()->routeIs('admin.history') ? 'active' : '' }}" href="{{ route('admin.history') }}"><span class="sa-icon">↺</span><span class="sa-nav-label">History</span></a>
            @endif
        @else
            <a class="{{ request()->routeIs('admin.contracts*') ? 'active' : '' }}" href="{{ route('admin.contracts') }}"><span class="sa-icon">♧</span><span class="sa-nav-label">Contracts</span></a>
        @endif
    </nav>
    <div class="sa-app">
        <header class="sa-top">
            <div class="sa-top-inner">
                <div class="sa-breadcrumb">
                    <span>Human Resource Information System</span>
                    <span class="sa-breadcrumb-separator" aria-hidden="true">›</span>
                    <strong>{{ $title }}</strong>
                </div>
            <div class="sa-top-actions">
                <div class="sa-header-popover" data-header-popover>
                    <button
                        class="sa-header-icon-button"
                        type="button"
                        aria-label="Notifications"
                        aria-haspopup="true"
                        aria-expanded="false"
                        aria-controls="notifications-panel"
                        data-header-popover-toggle
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
                        </svg>
                    </button>
                    <section id="notifications-panel" class="sa-header-popover-panel" role="region" aria-labelledby="notifications-title" hidden>
                        <h2 id="notifications-title">Notifications</h2>
                        <p>No notifications yet.</p>
                    </section>
                </div>
                <div class="sa-header-popover" data-header-popover>
                    <button
                        class="sa-header-icon-button"
                        type="button"
                        aria-label="Profile"
                        aria-haspopup="true"
                        aria-expanded="false"
                        aria-controls="profile-panel"
                        data-header-popover-toggle
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="9" />
                            <circle cx="12" cy="9" r="2.5" />
                            <path d="M7.5 18a4.5 4.5 0 0 1 9 0" />
                        </svg>
                    </button>
                    <section id="profile-panel" class="sa-header-popover-panel sa-profile-panel" role="region" aria-labelledby="profile-name" hidden>
                        <div class="sa-profile-summary">
                            <strong id="profile-name">{{ auth()->user()->name }}</strong>
                            <span>{{ auth()->user()->email }}</span>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="sa-logout-button" type="submit">Log out</button>
                        </form>
                    </section>
                </div>
            </div>
        </div>
        </header>
        <div class="sa-layout">
            <main class="sa-main">{{ $slot }}</main>
        </div>
    </div>
</body>
</html>
