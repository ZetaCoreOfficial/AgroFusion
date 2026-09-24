<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>@yield('title', 'AgroFusion | Panel')</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">

    <style>
        /* ═══════════ DESIGN TOKENS ═══════════ */
        :root {
            --sw: 260px;
            --sw-mini: 68px;
            --th: 60px;

            --bg:        #f0f4f8;
            --surface:   #ffffff;
            --border:    #e2e8f0;
            --border-2:  #cbd5e1;

            --txt-1:     #0f172a;
            --txt-2:     #475569;
            --txt-3:     #94a3b8;

            --brand:     #10b981;
            --brand-dk:  #059669;
            --brand-lt:  #ecfdf5;
            --brand-rng: rgba(16,185,129,.18);

            --sb-bg:     #0d1117;
            --sb-hover:  rgba(255,255,255,.055);
            --sb-active: rgba(16,185,129,.13);
            --sb-border: rgba(255,255,255,.07);
            --sb-txt:    #8b9ab0;
            --sb-txt-h:  #e2e8f0;
            --sb-txt-a:  #10b981;

            --sh-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --sh-md: 0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.05);
            --sh-lg: 0 16px 32px rgba(0,0,0,.12);

            --r:    8px;
            --r-sm: 6px;
            --r-lg: 14px;
        }

        /* ═══════════ RESET ═══════════ */
        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--txt-1);
            margin: 0;
            overflow-x: hidden;
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        a { color: var(--brand); }
        a:hover { color: var(--brand-dk); }

        /* ═══════════ LAYOUT SHELL ═══════════ */
        .ag-layout {
            display: flex;
            min-height: 100vh;
        }

        /* ═══════════════════════════════════════════════
           SIDEBAR
        ═══════════════════════════════════════════════ */
        .ag-sidebar {
            width: var(--sw);
            background: var(--sb-bg);
            position: fixed;
            inset: 0 auto 0 0;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            transition: width .22s cubic-bezier(.4,0,.2,1);
            overflow: hidden;
            border-right: 1px solid rgba(255,255,255,.05);
        }

        .ag-sidebar.mini { width: var(--sw-mini); }

        /* — Brand / Logo — */
        .ag-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 18px 14px;
            border-bottom: 1px solid var(--sb-border);
            text-decoration: none;
            flex-shrink: 0;
        }

        .ag-brand-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            box-shadow: 0 0 0 3px rgba(16,185,129,.2);
        }

        .ag-brand-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .ag-brand-text { overflow: hidden; transition: opacity .2s, width .22s; }
        .ag-brand-name { font-size: .88rem; font-weight: 700; color: #f1f5f9; white-space: nowrap; display: block; }
        .ag-brand-sub  { font-size: .68rem; color: #475569; white-space: nowrap; display: block; margin-top: 1px; }

        .ag-sidebar.mini .ag-brand-text { opacity: 0; width: 0; }

        /* — User Panel — */
        .ag-sb-user {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 10px;
            padding: 10px;
            background: rgba(255,255,255,.03);
            border: 1px solid var(--sb-border);
            border-radius: var(--r-sm);
            overflow: hidden;
        }

        .ag-sb-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            border: 2px solid rgba(16,185,129,.35);
        }

        .ag-sb-user-info { overflow: hidden; transition: opacity .2s, width .22s; min-width: 0; }
        .ag-sb-user-name { font-size: .78rem; font-weight: 600; color: #cbd5e1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
        .ag-sb-user-role { font-size: .66rem; color: #475569; white-space: nowrap; display: block; margin-top: 1px; }

        .ag-sidebar.mini .ag-sb-user { justify-content: center; }
        .ag-sidebar.mini .ag-sb-user-info { opacity: 0; width: 0; overflow: hidden; }

        /* — Nav Scroll Area — */
        .ag-sb-nav {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 6px 0 10px;
            overflow-anchor: none;
            overscroll-behavior: contain;
        }

        .ag-sb-nav::-webkit-scrollbar { width: 3px; }
        .ag-sb-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); border-radius: 3px; }

        /* — Section Labels — */
        .ag-nav-label {
            font-size: .6rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: #334155;
            padding: 14px 14px 4px;
            white-space: nowrap;
            display: block;
        }

        .ag-sidebar.mini .ag-nav-label { opacity: 0; height: 0; padding: 0; overflow: hidden; }

        /* — Nav Items — */
        .ag-nav-ul { list-style: none; margin: 0; padding: 0; }

        .ag-nav-li { margin: 1px 8px; }

        .ag-nav-a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 9px;
            border-radius: var(--r-sm);
            text-decoration: none !important;
            color: var(--sb-txt);
            font-size: .82rem;
            font-weight: 500;
            transition: background .12s, color .12s;
            cursor: pointer;
            white-space: nowrap;
            position: relative;
        }

        .ag-nav-a:hover { background: var(--sb-hover); color: var(--sb-txt-h); }

        .ag-nav-a.active {
            background: var(--sb-active);
            color: var(--sb-txt-a);
            font-weight: 600;
        }

        .ag-nav-a.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 25%;
            bottom: 25%;
            width: 2px;
            background: var(--brand);
            border-radius: 0 2px 2px 0;
        }

        .ag-nav-icon {
            width: 16px;
            text-align: center;
            font-size: .85rem;
            flex-shrink: 0;
            opacity: .8;
        }

        .ag-nav-a.active .ag-nav-icon,
        .ag-nav-a:hover .ag-nav-icon { opacity: 1; }

        .ag-nav-text { flex: 1; }
        .ag-sidebar.mini .ag-nav-text { display: none; }

        .ag-nav-arrow {
            font-size: .6rem;
            opacity: .4;
            transition: transform .2s, opacity .12s;
        }

        .ag-nav-a:hover .ag-nav-arrow { opacity: .7; }
        .ag-nav-a.group-open .ag-nav-arrow { transform: rotate(90deg); opacity: .7; }
        .ag-sidebar.mini .ag-nav-arrow { display: none; }

        /* — Submenu — */
        .ag-subnav {
            list-style: none;
            padding: 2px 0 4px 36px;
            margin: 0;
            overflow: hidden;
            max-height: 0;
            transition: max-height .28s ease;
        }

        .ag-subnav.open { max-height: 600px; }

        /* Submenús anidados (Catálogos, Reportes): colapsados no ocupan espacio */
        .ag-subnav.ag-subnav-nested:not(.open) {
            display: none;
            padding: 0;
            margin: 0;
        }

        .ag-subnav.ag-subnav-nested.open {
            display: block;
            padding: 2px 0 0 12px;
            margin-top: 2px;
            max-height: 280px;
        }
        .ag-sub-a[data-toggle-sub] { justify-content: space-between; }
        .ag-sub-a .ag-sub-arrow {
            font-size: .62rem;
            opacity: .45;
            margin-left: auto;
            transition: transform .2s;
        }
        .ag-sub-a.group-open .ag-sub-arrow { transform: rotate(90deg); opacity: .7; }
        .ag-sidebar.mini .ag-subnav { display: none; }

        .ag-sub-li { margin: 1px 8px 1px 0; }

        .ag-sub-a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 9px;
            border-radius: var(--r-sm);
            text-decoration: none !important;
            color: #4b5563;
            font-size: .79rem;
            transition: background .12s, color .12s;
        }

        .ag-sub-a::before {
            content: '';
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
            opacity: .35;
            transition: opacity .12s;
        }

        .ag-sub-a:hover { color: #c7d2e0; background: rgba(255,255,255,.04); }
        .ag-sub-a:hover::before { opacity: .7; }
        .ag-sub-a.active { color: var(--brand); font-weight: 600; }
        .ag-sub-a.active::before { opacity: 1; background: var(--brand); }

        /* — Sidebar Footer — */
        .ag-sb-footer {
            padding: 10px;
            border-top: 1px solid var(--sb-border);
            flex-shrink: 0;
        }

        .ag-sb-foot-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 8px 9px;
            border: none;
            background: none;
            border-radius: var(--r-sm);
            color: #475569;
            font-size: .79rem;
            font-family: inherit;
            cursor: pointer;
            transition: background .12s, color .12s;
            white-space: nowrap;
        }

        .ag-sb-foot-btn:hover { background: rgba(239,68,68,.08); color: #f87171; }
        a.ag-sb-foot-btn { text-decoration: none; }
        a.ag-sb-foot-btn:hover { text-decoration: none; color: #f87171; }
        .ag-sidebar.mini .ag-sb-foot-btn { justify-content: center; }
        .ag-sidebar.mini .ag-sb-foot-btn span { display: none; }

        /* ═══════════════════════════════════════════════
           MAIN AREA
        ═══════════════════════════════════════════════ */
        .ag-main {
            margin-left: var(--sw);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left .22s cubic-bezier(.4,0,.2,1);
        }

        .ag-main.mini { margin-left: var(--sw-mini); }

        /* ═══════════════════════════════════════════════
           TOPBAR
        ═══════════════════════════════════════════════ */
        .ag-topbar {
            height: var(--th);
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--sh-sm);
        }

        .ag-tb-icon-btn {
            width: 34px;
            height: 34px;
            border: none;
            background: transparent;
            border-radius: var(--r-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--txt-2);
            transition: background .12s, color .12s;
            flex-shrink: 0;
            text-decoration: none;
        }

        .ag-tb-icon-btn:hover { background: var(--bg); color: var(--txt-1); }

        .ag-tb-breadcrumb {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 6px;
            overflow: hidden;
        }

        .ag-tb-title {
            font-size: .92rem;
            font-weight: 600;
            color: var(--txt-1);
            white-space: nowrap;
        }

        .ag-tb-right {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-left: auto;
            flex-shrink: 0;
        }

        /* User dropdown */
        .ag-user-wrap { position: relative; }

        .ag-user-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 10px 5px 6px;
            border: 1px solid var(--border);
            border-radius: var(--r);
            background: transparent;
            cursor: pointer;
            transition: background .12s, border-color .12s;
        }

        .ag-user-btn:hover { background: var(--bg); border-color: var(--border-2); }

        .ag-user-btn img {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            object-fit: cover;
        }

        .ag-user-btn-name {
            font-size: .8rem;
            font-weight: 500;
            color: var(--txt-1);
            max-width: 120px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ag-user-btn i { font-size: .6rem; color: var(--txt-3); }

        .ag-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 210px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--r);
            box-shadow: var(--sh-lg);
            z-index: 999;
            display: none;
            overflow: hidden;
        }

        .ag-dropdown.show { display: block; }

        .ag-dd-header {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            background: var(--bg);
        }

        .ag-dd-user-name { font-size: .83rem; font-weight: 600; color: var(--txt-1); display: block; }
        .ag-dd-user-role { font-size: .72rem; color: var(--txt-3); display: block; margin-top: 2px; }

        .ag-dd-body { padding: 4px 0; }

        .ag-dd-item {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 8px 14px;
            color: var(--txt-2);
            font-size: .81rem;
            text-decoration: none !important;
            transition: background .12s;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            font-family: inherit;
        }

        .ag-dd-item:hover { background: var(--bg); color: var(--txt-1); }
        .ag-dd-item.danger:hover { background: #fef2f2; color: #dc2626; }

        .ag-dd-sep { height: 1px; background: var(--border); margin: 4px 0; }

        /* ═══════════════════════════════════════════════
           CONTENT AREA
        ═══════════════════════════════════════════════ */
        .ag-content {
            flex: 1;
            padding: 26px 28px;
        }

        /* ═══════════════════════════════════════════════
           FOOTER
        ═══════════════════════════════════════════════ */
        .ag-footer {
            padding: 14px 28px;
            border-top: 1px solid var(--border);
            background: var(--surface);
            font-size: .75rem;
            color: var(--txt-3);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* ═══════════════════════════════════════════════
           ROLE BADGES
        ═══════════════════════════════════════════════ */
        .role-badge {
            display: inline-flex;
            align-items: center;
            font-size: .62rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .role-badge.admin        { background: #fee2e2; color: #dc2626; }
        .role-badge.agricultor   { background: #d1fae5; color: #059669; }
        .role-badge.operador     { background: #dbeafe; color: #2563eb; }
        .role-badge.planta       { background: #ede9fe; color: #7c3aed; }
        .role-badge.transportista{ background: #ffedd5; color: #ea580c; }
        .role-badge.almacen      { background: #ccfbf1; color: #0d9488; }

        /* ═══════════════════════════════════════════════
           OVERRIDE BOOTSTRAP + ADMINLTE REMNANTS
        ═══════════════════════════════════════════════ */

        /* Cards */
        .card {
            border: 1px solid var(--border) !important;
            border-radius: var(--r) !important;
            box-shadow: var(--sh-sm) !important;
            transition: box-shadow .15s;
        }

        .card:hover { box-shadow: var(--sh-md) !important; }

        .card-header {
            background: var(--surface) !important;
            border-bottom: 1px solid var(--border) !important;
            padding: 14px 18px !important;
            font-size: .87rem !important;
            font-weight: 600 !important;
            color: var(--txt-1) !important;
            border-radius: var(--r) var(--r) 0 0 !important;
        }

        .card-body { padding: 18px !important; }

        .card-footer {
            background: var(--bg) !important;
            border-top: 1px solid var(--border) !important;
            padding: 10px 18px !important;
            border-radius: 0 0 var(--r) var(--r) !important;
        }

        /* Stat boxes (small-box) */
        .small-box {
            border-radius: var(--r) !important;
            border: none !important;
            box-shadow: var(--sh-sm) !important;
            overflow: hidden;
            transition: none;
            position: relative;
        }

        .small-box:hover {
            transform: none;
            box-shadow: var(--sh-sm) !important;
        }

        .small-box .inner {
            padding: 16px 18px;
        }

        .small-box .inner h3 {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            margin: 0 0 4px;
        }

        .small-box .inner p {
            font-size: .82rem;
            margin: 0;
            opacity: .88;
            font-weight: 500;
        }

        .small-box > .icon {
            position: absolute;
            right: 10px;
            top: 10px;
            transform: none;
            font-size: 2.5rem !important;
            opacity: 1;
        }

        /* Pie de stat box: ver partials/small-box-footer-styles.blade.php */

        /* KPI de módulos: ver partials/estilos-kpi-ejecutivo.blade.php */

        /* Tables */
        .table { font-size: .83rem; }

        .table thead th {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--txt-3);
            border-bottom: 2px solid var(--border) !important;
            padding: 10px 14px;
            background: #fafbfc;
            vertical-align: middle;
        }

        .table tbody td {
            padding: 11px 14px;
            vertical-align: middle;
            border-color: var(--border);
            color: var(--txt-2);
        }

        .table-hover tbody tr:hover td { background: #f8fafc; }

        /* Buttons */
        .btn {
            font-weight: 600 !important;
            font-size: .81rem !important;
            border-radius: var(--r-sm) !important;
            letter-spacing: .01em;
            transition: all .14s !important;
        }

        .btn-primary {
            background: var(--brand) !important;
            border-color: var(--brand) !important;
            color: #fff !important;
        }

        .btn-primary:hover {
            background: var(--brand-dk) !important;
            border-color: var(--brand-dk) !important;
            box-shadow: 0 4px 12px rgba(16,185,129,.3) !important;
        }

        .btn-success { background: #059669 !important; border-color: #059669 !important; }
        .btn-success:hover { background: #047857 !important; border-color: #047857 !important; box-shadow: 0 4px 12px rgba(5,150,105,.3) !important; }

        .btn-info { background: #2563eb !important; border-color: #2563eb !important; }
        .btn-warning { background: #d97706 !important; border-color: #d97706 !important; color: #fff !important; }
        .btn-danger { background: #dc2626 !important; border-color: #dc2626 !important; }

        .btn:not(.btn-link):hover { transform: translateY(-1px); }
        .btn:active { transform: translateY(0) !important; }

        /* Forms — altura compatible con Bootstrap (evita campos “cortados” en filtros) */
        .form-control {
            border: 1px solid var(--border);
            border-radius: var(--r-sm) !important;
            font-size: .875rem;
            padding: 0.4375rem 0.75rem;
            min-height: calc(1.5em + 0.875rem + 2px);
            height: auto !important;
            line-height: 1.5;
            color: var(--txt-1);
            transition: border .14s, box-shadow .14s;
            font-family: 'Inter', sans-serif;
        }

        .form-control-sm {
            font-size: .8125rem;
            padding: 0.3125rem 0.625rem;
            min-height: calc(1.5em + 0.625rem + 2px);
            height: auto !important;
            line-height: 1.5;
        }

        select.form-control {
            padding-right: 1.75rem;
        }

        .input-group > .form-control,
        .input-group-sm > .form-control {
            height: auto !important;
        }

        .input-group:not(.input-group-sm) > .form-control {
            min-height: calc(1.5em + 0.875rem + 2px);
        }

        .input-group-sm > .form-control {
            min-height: calc(1.5em + 0.625rem + 2px);
        }

        .input-group-prepend .input-group-text,
        .input-group-append .input-group-text {
            height: auto !important;
            min-height: calc(1.5em + 0.875rem + 2px);
            display: flex;
            align-items: center;
            line-height: 1.5;
            padding: 0.4375rem 0.75rem;
        }

        .input-group-sm > .input-group-prepend > .input-group-text,
        .input-group-sm > .input-group-append > .input-group-text {
            min-height: calc(1.5em + 0.625rem + 2px);
            padding: 0.3125rem 0.625rem;
            font-size: .8125rem;
        }

        .filtros-panel label.small,
        .filtros-panel .small.text-muted {
            display: block;
            margin-bottom: 0.35rem;
            line-height: 1.3;
        }

        .card-tools {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .card-modulo-main > .card-header {
            background: #fff;
            border-bottom: 1px solid #dee2e6;
            padding: 0.75rem 1rem;
        }

        .card-modulo-main > .card-header .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1a252f;
        }

        .card-modulo-main .badge-registros {
            font-size: 0.78rem;
            font-weight: 500;
            padding: 0.35em 0.65em;
            white-space: nowrap;
        }

        .card-modulo-main .view-toggle .btn {
            border-color: #ced4da;
            color: #6c757d;
        }

        .card-modulo-main .view-toggle .btn.active {
            background: #2c5530;
            border-color: #2c5530;
            color: #fff;
        }

        .filtros-panel {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 1rem 1.25rem;
        }

        .card-tools .btn-filtros-toggle {
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            font-weight: 500;
        }

        .form-control:focus {
            border-color: var(--brand) !important;
            box-shadow: 0 0 0 3px var(--brand-rng) !important;
            outline: none;
        }

        label, .form-label {
            font-size: .8rem;
            font-weight: 500;
            color: var(--txt-2);
            margin-bottom: 5px;
            display: block;
        }

        /* Badges */
        .badge {
            border-radius: 20px !important;
            font-weight: 600;
            font-size: .68rem;
            padding: .28em .7em;
        }

        /* Alerts legacy (formularios internos) */
        .alert {
            border-radius: var(--r) !important;
            border-width: 1px !important;
            font-size: .84rem;
            padding: 11px 15px;
        }
        .alert-success { background: #f8fafc !important; border-color: #e2e8f0 !important; color: #334155 !important; }
        .alert-danger  { background: #fff5f5 !important; border-color: #fecaca !important; color: #991b1b !important; }
        .alert-warning { background: #fffbeb !important; border-color: #fde68a !important; color: #92400e !important; }
        .alert-info    { background: #eff6ff !important; border-color: #bfdbfe !important; color: #1d4ed8 !important; }

        /* Flash AgroFusion */
        .ag-flash {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .65rem .85rem;
            margin-bottom: .85rem;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #fff;
            box-shadow: 0 2px 10px rgba(15, 23, 42, .06);
            font-size: .84rem;
            line-height: 1.45;
            color: #1e293b;
        }
        .ag-flash__icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: .72rem;
        }
        .ag-flash--success .ag-flash__icon { background: #dcfce7; color: #166534; }
        .ag-flash--warning .ag-flash__icon { background: #fef3c7; color: #b45309; }
        .ag-flash--error .ag-flash__icon { background: #fee2e2; color: #b91c1c; }
        .ag-flash__text { flex: 1; font-weight: 600; line-height: 1.35; }
        .ag-flash__close {
            border: 0;
            background: transparent;
            color: #94a3b8;
            padding: .35rem;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            align-self: center;
        }
        .ag-flash__close:hover { color: #64748b; }

        /* Pagination */
        .pagination .page-link {
            border-radius: var(--r-sm) !important;
            margin: 0 2px;
            color: var(--brand);
            border-color: var(--border);
            font-size: .8rem;
            padding: 5px 10px;
        }

        .pagination .page-item.active .page-link { background: var(--brand); border-color: var(--brand); color: #fff; }
        .pagination .page-link:hover { background: var(--brand-lt); color: var(--brand-dk); }

        /* Breadcrumb */
        .breadcrumb {
            background: transparent !important;
            padding: 0 !important;
            margin: 0 !important;
            font-size: .78rem;
        }

        .breadcrumb-item + .breadcrumb-item::before { color: var(--txt-3); }
        .breadcrumb-item a { color: var(--brand); text-decoration: none; }
        .breadcrumb-item.active { color: var(--txt-3); }

        /* Content header */
        .content-header { padding: 0 0 22px; }
        .content-header h1, .content-header .h1 {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--txt-1);
            margin: 0;
        }

        /* ── Info Box (AdminLTE compat) ── */
        .info-box {
            display: flex;
            align-items: stretch;
            min-height: 70px;
            border-radius: var(--r) !important;
            box-shadow: var(--sh-sm) !important;
            margin-bottom: 14px;
            overflow: hidden;
            transition: transform .15s, box-shadow .15s;
        }

        .info-box:hover { transform: translateY(-2px); box-shadow: var(--sh-md) !important; }

        .info-box-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            flex-shrink: 0;
            font-size: 1.5rem;
            background: rgba(0,0,0,.15);
            color: #fff;
        }

        .info-box-content {
            padding: 9px 14px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .info-box-text {
            display: block;
            font-size: .72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .info-box-number {
            display: block;
            font-size: 1.1rem;
            font-weight: 700;
            margin-top: 2px;
        }

        .info-box.bg-secondary { background: #64748b !important; color: #fff !important; }
        .info-box.bg-info      { background: #fff !important; color: #1e293b !important; border-top: 3px solid #2563eb; }
        .info-box.bg-primary   { background: #fff !important; color: #1e293b !important; border-top: 3px solid #2c5530; }
        .info-box.bg-success   { background: #fff !important; color: #1e293b !important; border-top: 3px solid #2c5530; }
        .info-box.bg-warning   { background: #fff !important; color: #1e293b !important; border-top: 3px solid #d97706; }
        .info-box.bg-dark      { background: #fff !important; color: #1e293b !important; border-top: 3px solid #64748b; }

        /* Compat wrappers (AdminLTE remnants used in some views) */
        .content-wrapper { background: transparent !important; }
        .main-header, .main-sidebar { display: none !important; }

        /* ═══════════════════════════════════════════════
           RESPONSIVE
        ═══════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .ag-sidebar {
                transform: translateX(-100%);
                width: var(--sw) !important;
            }

            .ag-sidebar.mobile-open { transform: translateX(0); }

            .ag-main { margin-left: 0 !important; }
            .ag-content { padding: 16px; }
            .ag-tb-breadcrumb { display: none; }
        }

        /* ═══════════════════════════════════════════════
           MOBILE OVERLAY
        ═══════════════════════════════════════════════ */
        .ag-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 999;
        }

        .ag-overlay.show { display: block; }
    </style>

    @stack('styles')
    @include('partials.estilos-kpi-ejecutivo')
    @include('partials.estilos-ejecutivo-global')
    @include('dashboard.partials.estilos-dash-ejecutivo')
    @include('partials.small-box-footer-styles')
    @include('partials.login-notificacion-estilos')
</head>

@php
    $authUser    = auth()->user();
    $userFull    = $authUser ? trim(($authUser->nombre ?? '') . ' ' . ($authUser->apellido ?? '')) : 'Usuario';
    if ($authUser && !$userFull) $userFull = $authUser->nombreusuario ?? 'Usuario';
    $userImg     = $authUser ? $authUser->avatarUrl() : \App\Support\UsuarioAvatar::placeholder();
    $userImgFallback = \App\Support\UsuarioAvatar::placeholder();
    $userRole    = $authUser ? ($authUser->getRoleNames()->first() ?? 'sin rol') : 'invitado';
    $isAdmin     = \App\Support\UsuarioRol::esAdminGlobal($authUser);
    $esJefeAgr = $authUser && \App\Support\UsuarioRol::esJefeAgricultor($authUser);
    $esAgricultorOperativo = $authUser && \App\Support\UsuarioRol::debeAcotarPorAsignacion($authUser);
    $esPlantaOperativo = $authUser && \App\Support\UsuarioRol::esPlantaOperativo($authUser) && ! $isAdmin;
    $esTransportistaOperativo = $authUser && \App\Support\UsuarioRol::esTransportista($authUser) && ! $isAdmin;
    $esMinoristaOperativo = $authUser && \App\Support\UsuarioRol::esMinorista($authUser) && ! $isAdmin;
    $esMayoristaOperativo = $authUser && \App\Support\UsuarioRol::esMayorista($authUser) && ! $isAdmin;
    $pendientesSolicitudes = $isAdmin
        ? \App\Models\Usuario::where('estado_cuenta', \App\Support\CuentaEstado::PENDIENTE)->count()
        : 0;
    $roleCss     = strtolower($userRole);
@endphp

<body>

<div class="ag-overlay" id="agOverlay"></div>

<div class="ag-layout">

    {{-- ═══════════════ SIDEBAR ═══════════════ --}}
    <aside class="ag-sidebar" id="agSidebar">

        {{-- Logo --}}
        <a href="{{ route('dashboard') }}" class="ag-brand">
            <div class="ag-brand-icon">
                <img src="{{ asset('images/agrofusion-logo.jpeg') }}" alt="AgroFusion">
            </div>
            <div class="ag-brand-text">
                <span class="ag-brand-name">AgroFusion</span>
                <span class="ag-brand-sub">Panel de trazabilidad</span>
            </div>
        </a>

        {{-- User panel --}}
        <div class="ag-sb-user">
            <img src="{{ $userImg }}" alt="Avatar" class="ag-sb-user-avatar"
                 data-avatar-fallback="{{ $userImgFallback }}"
                 onerror="if(this.dataset.avatarFallback){this.onerror=null;this.src=this.dataset.avatarFallback;}">
            <div class="ag-sb-user-info">
                <span class="ag-sb-user-name">{{ $userFull }}</span>
                <span class="ag-sb-user-role">{{ ucfirst($userRole) }}</span>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="ag-sb-nav">
            <ul class="ag-nav-ul">

                {{-- Dashboard --}}
                <li class="ag-nav-li">
                    <a href="{{ route('dashboard') }}"
                       class="ag-nav-a {{ request()->routeIs('dashboard') && !request()->routeIs('dashboard.panel-*') ? 'active' : '' }}">
                        <i class="ag-nav-icon fas fa-home"></i>
                        <span class="ag-nav-text">Inicio</span>
                    </a>
                </li>

                @php
                    $puedeAlmacenAgricola = $isAdmin || ($authUser && $authUser->hasRole('agricultor')) || $esJefeAgr;
                    $puedeAlmacenMayorista = $isAdmin || \App\Support\UsuarioRol::esMayorista($authUser);
                    $puedeMenuMayorista = $puedeAlmacenMayorista;
                    $mayMenuOpen = request()->routeIs('almacen-mayorista.*');
                    $enPedidosDist = request()->routeIs('punto-venta.pedidos.*') && ! request()->routeIs('punto-venta.rutas.*');
                    $ctxPedidosDist = request()->query('ctx');
                    $esFlujoSolicitudMinorista = request()->routeIs(
                        'punto-venta.pedidos.create',
                        'punto-venta.pedidos.store'
                    ) || $ctxPedidosDist === 'pdv';
                    $esBandejaMayoristaNav = request()->routeIs('punto-venta.pedidos.*')
                        && ! request()->routeIs('punto-venta.rutas.*')
                        && ($ctxPedidosDist === 'mayorista'
                            || (\App\Support\UsuarioRol::esMayorista($authUser) && ! \App\Support\UsuarioRol::esMinorista($authUser) && $ctxPedidosDist !== 'pdv'));
                    $pedidosEnSeccionPdv = $enPedidosDist && ($esMinoristaOperativo || $esFlujoSolicitudMinorista);
                    $pedidosEnSeccionMayorista = $enPedidosDist && ! $pedidosEnSeccionPdv && ($esMayoristaOperativo || ($isAdmin && $puedeMenuMayorista) || $esBandejaMayoristaNav);
                    $mayMenuOpen = $mayMenuOpen || $pedidosEnSeccionMayorista;
                    $almAgrOpen = request()->routeIs('almacen-agricola.*');
                    $almPlaOpen = request()->routeIs('almacen-planta.*');
                    $almMayOpen = request()->routeIs('almacen-mayorista.*');
                    $prodAgrOpen = request()->routeIs('producciones.*', 'climas.*');
                    if (! ($esJefeAgr && ! $isAdmin)) {
                    }
                    $prodPlaMenuOpen = request()->routeIs('procesos-planta.*', 'plantillas-transformacion.*', 'maquinas-planta.*', 'variables-estandar.*', 'procesamiento.*', 'tareas-planta.*', 'certificaciones-planta.*', 'produccion-planta.catalogos.*');
                    $envListadoActivo = request()->routeIs(
                        'logistica.asignaciones.listado',
                        'logistica.asignaciones.show',
                        'logistica.asignaciones.edit',
                        'pedidos.index',
                        'pedidos.show',
                        'pedidos.edit',
                        'pedidos.create',
                        'envios.mandar',
                        'envios.crear'
                    ) && ! request()->routeIs('logistica.rutas-tiempo-real.*', 'logistica.documentos.*');
                    $showProdAgricola = ! $esPlantaOperativo && ! $esTransportistaOperativo && ! $esMayoristaOperativo && (
                        $isAdmin
                        || $esJefeAgr
                        || ($authUser && $authUser->hasRole('agricultor'))
                        || auth()->user()?->canany(['lotes.view','lotes.create','lotes.update','lotes.delete'])
                        || auth()->user()?->can('certificaciones.view')
                        || auth()->user()?->canany(['inventario.view','inventario.create','inventario.update','inventario.delete'])
                        || $puedeAlmacenAgricola
                    );
                    $envPerm = [
                        'envios.view', 'envios.create', 'envios.admin.view',
                        'vehiculos.view', 'transportistas.view', 'direcciones.view',
                        'pedidos.view',
                        'asignaciones.view', 'rutas_multi.view', 'rutas_multi.create',
                        'documentos.view', 'incidentes.view',
                    ];
                    $puedeReportesLogistica = $isAdmin;
                    $puedeSubmenuEnviosScoped = \App\Support\RutaTiempoRealAcceso::puedeAccederModulo($authUser)
                        || \App\Support\DocumentoEntregaAcceso::puedeAccederModulo($authUser);
                    $logMasOpen = request()->routeIs('envios.reportes-distribucion');
                    $catalogoLogisticaOpen = request()->routeIs('envios.catalogos.*');
                    $catalogoTipoActivo = $catalogoLogisticaOpen ? request()->route('tipo') : null;
                    $esJefePlantaNav = $authUser && \App\Support\UsuarioRol::esJefePlanta($authUser);
                    $usaEnviosEnSeccionRol = ($esJefePlantaNav || $esJefeAgr || $esMayoristaOperativo) && ! $isAdmin;
                    $envOpen = request()->routeIs(
                        'envios.*',
                        'pedidos.create',
                        'pedidos.show',
                        'pedidos.edit',
                        'logistica.asignaciones.*',
                        'logistica.documentos.*',
                        'logistica.rutas-tiempo-real.*'
                    ) || $logMasOpen || request()->routeIs('pedidos.index');
                    $envPlantaOpen = ($envOpen || $catalogoLogisticaOpen) && ($esJefePlantaNav || $esPlantaOperativo);
                    $envAgrOpen = ($envOpen || $catalogoLogisticaOpen) && $esJefeAgr;
                    $envMayOpen = ($envOpen || $catalogoLogisticaOpen) && $esMayoristaOperativo;
                    $puedeEnvios = $isAdmin || ($authUser && $authUser->hasAnyPermission($envPerm));
                    $showOperLogistica = $isAdmin || $esTransportistaOperativo || (
                        $puedeEnvios && ! $esPlantaOperativo && ! $esAgricultorOperativo && ! $usaEnviosEnSeccionRol
                    );
                    $showProdPlantaSection = $isAdmin || $esPlantaOperativo || ($esJefePlantaNav && ! $isAdmin);
                    $puedeProdPlanta = $isAdmin || $esPlantaOperativo || $esJefePlantaNav;
                    $puedeAlmacenPlanta = $isAdmin || $esPlantaOperativo || $esJefePlantaNav;
                @endphp

                {{-- TEMPORAL (solo UX): el admin no ve las secciones operativas en el sidebar. No afecta autorización backend. --}}
                @if(! $isAdmin)
                @if($showProdAgricola)
                <span class="ag-nav-label">Producción agrícola</span>

                @can('panel_agricultor.view')
                @if($esJefeAgr || $isAdmin)
                <li class="ag-nav-li">
                    <a href="{{ route('dashboard.panel-agricola') }}" class="ag-nav-a {{ request()->routeIs('dashboard.panel-agricola') ? 'active' : '' }}">
                        <i class="ag-nav-icon fas fa-tractor"></i>
                        <span class="ag-nav-text">Panel Agrícola</span>
                    </a>
                </li>
                @endif
                @endcan

                {{-- Lotes & Actividades --}}
                @canany(['lotes.view','lotes.create','lotes.update','lotes.delete'])
                @php $lotesOpen = request()->routeIs('lotes.*','actividades.*','certificaciones.*'); @endphp
                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $lotesOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-lotes">
                        <i class="ag-nav-icon fas fa-map-marked-alt"></i>
                        <span class="ag-nav-text">Lotes y actividades</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $lotesOpen ? 'open' : '' }}" id="sub-lotes">
                        @can('lotes.view')
                        <li class="ag-sub-li"><a href="{{ route('lotes.index') }}" class="ag-sub-a {{ request()->routeIs('lotes.index', 'lotes.show', 'lotes.edit', 'lotes.create', 'lotes.trazabilidad', 'lotes.ubicacion') ? 'active' : '' }}">Lotes</a></li>
                        <li class="ag-sub-li"><a href="{{ route('lotes.mapa') }}" class="ag-sub-a {{ request()->routeIs('lotes.mapa') ? 'active' : '' }}">Mapa de lotes</a></li>
                        @endcan
                        <li class="ag-sub-li"><a href="{{ route('actividades.index') }}" class="ag-sub-a {{ request()->routeIs('actividades.index', 'actividades.show', 'actividades.edit', 'actividades.create') ? 'active' : '' }}">Actividades</a></li>
                        <li class="ag-sub-li"><a href="{{ route('actividades.calendario') }}" class="ag-sub-a {{ request()->routeIs('actividades.calendario') ? 'active' : '' }}">Calendario</a></li>
                        @can('certificaciones.view')
                        <li class="ag-sub-li"><a href="{{ route('certificaciones.index') }}" class="ag-sub-a {{ request()->routeIs('certificaciones.*') ? 'active' : '' }}">Certificaciones</a></li>
                        @endcan
                    </ul>
                </li>
                @endcanany

                {{-- Producción agrícola (cosechas y clima) --}}
                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $prodAgrOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-prod-agr">
                        <i class="ag-nav-icon fas fa-seedling"></i>
                        <span class="ag-nav-text">Producción</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $prodAgrOpen ? 'open' : '' }}" id="sub-prod-agr">
                        @unless(auth()->user()?->hasRole('transportista'))
                        <li class="ag-sub-li"><a href="{{ route('producciones.index') }}" class="ag-sub-a {{ request()->routeIs('producciones.*') ? 'active' : '' }}">Cosechas</a></li>
                        @endunless
                        <li class="ag-sub-li"><a href="{{ route('climas.index') }}" class="ag-sub-a {{ request()->routeIs('climas.*') ? 'active' : '' }}">Clima</a></li>
                    </ul>
                </li>

                {{-- Inventario --}}
                @canany(['inventario.view','inventario.create','inventario.update','inventario.delete'])
                @php $invOpen = request()->routeIs('insumos.*'); @endphp
                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $invOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-inv">
                        <i class="ag-nav-icon fas fa-boxes"></i>
                        <span class="ag-nav-text">Inventario</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $invOpen ? 'open' : '' }}" id="sub-inv">
                        <li class="ag-sub-li"><a href="{{ route('insumos.index') }}" class="ag-sub-a {{ request()->routeIs('insumos.*') ? 'active' : '' }}">Insumos</a></li>
                    </ul>
                </li>
                @endcanany

                @if($puedeAlmacenAgricola)
                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $almAgrOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-alm-agr">
                        <i class="ag-nav-icon fas fa-warehouse"></i>
                        <span class="ag-nav-text">Almacén agrícola</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $almAgrOpen ? 'open' : '' }}" id="sub-alm-agr">
                        <li class="ag-sub-li"><a href="{{ route('almacen-agricola.index') }}" class="ag-sub-a {{ request()->routeIs('almacen-agricola.index', 'almacen-agricola.show', 'almacen-agricola.create', 'almacen-agricola.edit') ? 'active' : '' }}">Almacenes</a></li>
                        @can('almacen.movimientos.view')
                        <li class="ag-sub-li"><a href="{{ route('almacen-agricola.movimientos.index') }}" class="ag-sub-a {{ request()->routeIs('almacen-agricola.movimientos.*') && !request()->routeIs('almacen-agricola.movimientos.reportes') ? 'active' : '' }}">Movimientos</a></li>
                        @endcan
                        @can('almacen.reportes.view')
                        <li class="ag-sub-li"><a href="{{ route('almacen-agricola.movimientos.reportes') }}" class="ag-sub-a {{ request()->routeIs('almacen-agricola.movimientos.reportes') ? 'active' : '' }}">Reportes</a></li>
                        @endcan
                    </ul>
                </li>
                @endif

                @if($esJefeAgr && ! $isAdmin && ($puedeEnvios || $puedeSubmenuEnviosScoped))
                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $envAgrOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-env-agr">
                        <i class="ag-nav-icon fas fa-truck"></i>
                        <span class="ag-nav-text">Envíos</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $envAgrOpen ? 'open' : '' }}" id="sub-env-agr">
                        @include('layouts.partials.submenu-envios-items', ['envListadoActivo' => $envListadoActivo])
                        @include('layouts.partials.submenu-catalogos-logistica', ['catalogoSubId' => 'agr'])
                    </ul>
                </li>
                @endif
                @endif

                @if($showOperLogistica)
                <span class="ag-nav-label">Operación logística</span>

                @can('panel_transportista.view')
                <li class="ag-nav-li"><a href="{{ route('dashboard.panel-transportista') }}" class="ag-nav-a {{ request()->routeIs('dashboard.panel-transportista') ? 'active' : '' }}"><i class="ag-nav-icon fas fa-truck-moving"></i><span class="ag-nav-text">Panel Transportista</span></a></li>
                @endcan

                {{-- Envíos --}}
                @if($puedeEnvios)
                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $envOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-env">
                        <i class="ag-nav-icon fas fa-truck"></i>
                        <span class="ag-nav-text">Envíos</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $envOpen ? 'open' : '' }}" id="sub-env">
                        @include('layouts.partials.submenu-envios-items', ['envListadoActivo' => $envListadoActivo])
                        {{-- Rutas multientrega ocultas: el flujo operativo es almacén agrícola → planta (un solo trayecto). --}}
                        @unless(auth()->user()?->hasRole('transportista'))
                        @if($isAdmin || auth()->user()?->can('transportistas.view'))
                        <li class="ag-sub-li"><a href="{{ route('envios.transportistas') }}" class="ag-sub-a {{ request()->routeIs('envios.transportistas*') ? 'active' : '' }}">Transportistas</a></li>
                        @endif
                        @if($isAdmin || auth()->user()?->can('vehiculos.view'))
                        <li class="ag-sub-li"><a href="{{ route('envios.vehiculos') }}" class="ag-sub-a {{ request()->routeIs('envios.vehiculos*') ? 'active' : '' }}">Vehículos</a></li>
                        @endif
                        @include('layouts.partials.submenu-catalogos-logistica', ['catalogoSubId' => 'admin'])
                        @endunless
                        @if($puedeReportesLogistica)
                        <li class="ag-sub-li">
                            <a href="#" class="ag-sub-a {{ $logMasOpen ? 'group-open' : '' }}" data-toggle-sub="sub-log-mas">
                                Reportes
                                <i class="fas fa-chevron-right ag-sub-arrow"></i>
                            </a>
                            <ul class="ag-subnav ag-subnav-nested {{ $logMasOpen ? 'open' : '' }}" id="sub-log-mas">
                                @if(auth()->user()?->can('envios.view') && auth()->user()?->can('reportes.view'))
                                <li class="ag-sub-li"><a href="{{ route('envios.reportes-distribucion') }}" class="ag-sub-a {{ request()->routeIs('envios.reportes-distribucion') ? 'active' : '' }}">Reportes distribución</a></li>
                                @endif
                            </ul>
                        </li>
                        @endif
                    </ul>
                </li>
                @endif
                @endif

                @if($showProdPlantaSection)
                <span class="ag-nav-label">Producción planta</span>

                @can('panel_planta.view')
                @if(! ($authUser && \App\Support\UsuarioRol::esOperarioPlanta($authUser)))
                <li class="ag-nav-li"><a href="{{ route('dashboard.panel-planta') }}" class="ag-nav-a {{ request()->routeIs('dashboard.panel-planta') ? 'active' : '' }}"><i class="ag-nav-icon fas fa-industry"></i><span class="ag-nav-text">Panel Planta</span></a></li>
                @endif
                @endcan

                @if($puedeProdPlanta)
                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $prodPlaMenuOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-prod-pla">
                        <i class="ag-nav-icon fas fa-cogs"></i>
                        <span class="ag-nav-text">Producción</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $prodPlaMenuOpen ? 'open' : '' }}" id="sub-prod-pla">
                        @can('lote_produccion.view')
                        <li class="ag-sub-li"><a href="{{ route('procesamiento.index') }}" class="ag-sub-a {{ request()->routeIs('procesamiento.*') && ! request()->routeIs('certificaciones-planta.*') ? 'active' : '' }}">Procesamiento de Lote</a></li>
                        @if($isAdmin || \App\Support\UsuarioRol::gestionaPlanta($authUser))
                        <li class="ag-sub-li"><a href="{{ route('certificaciones-planta.index') }}" class="ag-sub-a {{ request()->routeIs('certificaciones-planta.*') ? 'active' : '' }}">Certificaciones</a></li>
                        @endif
                        @endcan
                        @if($authUser && \App\Support\UsuarioRol::esOperarioPlanta($authUser))
                        <li class="ag-sub-li"><a href="{{ route('tareas-planta.index') }}" class="ag-sub-a {{ request()->routeIs('tareas-planta.*') ? 'active' : '' }}">Mis tareas de transformación</a></li>
                        @endif
                        @if($isAdmin || \App\Support\UsuarioRol::gestionaPlanta($authUser))
                        <li class="ag-sub-li"><a href="{{ route('procesos-planta.index') }}" class="ag-sub-a {{ request()->routeIs('procesos-planta.*') ? 'active' : '' }}">Procesos de planta</a></li>
                        <li class="ag-sub-li"><a href="{{ route('plantillas-transformacion.index') }}" class="ag-sub-a {{ request()->routeIs('plantillas-transformacion.*') ? 'active' : '' }}">Procesos de transformación</a></li>
                        <li class="ag-sub-li"><a href="{{ route('maquinas-planta.index') }}" class="ag-sub-a {{ request()->routeIs('maquinas-planta.*') ? 'active' : '' }}">Máquinas de planta</a></li>
                        <li class="ag-sub-li"><a href="{{ route('variables-estandar.index') }}" class="ag-sub-a {{ request()->routeIs('variables-estandar.*') ? 'active' : '' }}">Variables estándar</a></li>
                        <li class="ag-sub-li"><a href="{{ route('produccion-planta.catalogos.index', 'tipos-empaque') }}" class="ag-sub-a {{ request()->routeIs('produccion-planta.catalogos.*') ? 'active' : '' }}">Tipos de empaque</a></li>
                        @elseif($authUser && \App\Support\UsuarioRol::esOperarioPlanta($authUser))
                        <li class="ag-sub-li"><a href="{{ route('procesos-planta.index') }}" class="ag-sub-a {{ request()->routeIs('procesos-planta.*') ? 'active' : '' }}">Procesos de planta</a></li>
                        <li class="ag-sub-li"><a href="{{ route('plantillas-transformacion.index') }}" class="ag-sub-a {{ request()->routeIs('plantillas-transformacion.*') ? 'active' : '' }}">Procesos de transformación</a></li>
                        <li class="ag-sub-li"><a href="{{ route('maquinas-planta.index') }}" class="ag-sub-a {{ request()->routeIs('maquinas-planta.*') ? 'active' : '' }}">Máquinas de planta</a></li>
                        <li class="ag-sub-li"><a href="{{ route('variables-estandar.index') }}" class="ag-sub-a {{ request()->routeIs('variables-estandar.*') ? 'active' : '' }}">Variables estándar</a></li>
                        <li class="ag-sub-li"><a href="{{ route('produccion-planta.catalogos.index', 'tipos-empaque') }}" class="ag-sub-a {{ request()->routeIs('produccion-planta.catalogos.*') ? 'active' : '' }}">Tipos de empaque</a></li>
                        @endif
                    </ul>
                </li>
                @endif

                @if($puedeAlmacenPlanta)
                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $almPlaOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-alm-pla">
                        <i class="ag-nav-icon fas fa-dolly"></i>
                        <span class="ag-nav-text">Almacén de planta</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $almPlaOpen ? 'open' : '' }}" id="sub-alm-pla">
                        <li class="ag-sub-li"><a href="{{ route('almacen-planta.index') }}" class="ag-sub-a {{ request()->routeIs('almacen-planta.index', 'almacen-planta.show', 'almacen-planta.create', 'almacen-planta.edit', 'almacen-planta.inventario.*') ? 'active' : '' }}">Almacenes</a></li>
                        @can('almacen.movimientos.view')
                        <li class="ag-sub-li"><a href="{{ route('almacen-planta.movimientos.index') }}" class="ag-sub-a {{ request()->routeIs('almacen-planta.movimientos.*') && !request()->routeIs('almacen-planta.movimientos.reportes') ? 'active' : '' }}">Movimientos</a></li>
                        @endcan
                        @can('almacen.reportes.view')
                        <li class="ag-sub-li"><a href="{{ route('almacen-planta.movimientos.reportes') }}" class="ag-sub-a {{ request()->routeIs('almacen-planta.movimientos.reportes') ? 'active' : '' }}">Reportes</a></li>
                        @endcan
                    </ul>
                </li>
                @endif

                @if(($esJefePlantaNav || $esPlantaOperativo) && ! $isAdmin && ($puedeEnvios || $puedeSubmenuEnviosScoped))
                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $envPlantaOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-env-planta">
                        <i class="ag-nav-icon fas fa-truck"></i>
                        <span class="ag-nav-text">Envíos</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $envPlantaOpen ? 'open' : '' }}" id="sub-env-planta">
                        @include('layouts.partials.submenu-envios-items', ['envListadoActivo' => $envListadoActivo])
                        @include('layouts.partials.submenu-catalogos-logistica', ['catalogoSubId' => 'planta'])
                    </ul>
                </li>
                @endif
                @endif

                @php
                    $puedePedidosDistMenuPdv = $isAdmin || $esMinoristaOperativo;
                    $puedePuntoVentaMenu = $isAdmin || $esMinoristaOperativo
                        || ($authUser && $authUser->can('punto_venta.view') && ! $esMayoristaOperativo);
                    $pvMenuOpen = request()->routeIs('punto-venta.puntos.*', 'punto-venta.inventario.*')
                        || ($pedidosEnSeccionPdv ?? false);
                @endphp

                @if($puedeMenuMayorista)
                <span class="ag-nav-label">Mayorista</span>

                @if($esMayoristaOperativo || $isAdmin)
                <li class="ag-nav-li">
                    <a href="{{ route('dashboard.panel-mayorista') }}" class="ag-nav-a {{ request()->routeIs('dashboard.panel-mayorista') ? 'active' : '' }}">
                        <i class="ag-nav-icon fas fa-warehouse"></i>
                        <span class="ag-nav-text">Panel Mayorista</span>
                    </a>
                </li>
                @endif

                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $mayMenuOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-mayorista">
                        <i class="ag-nav-icon fas fa-boxes"></i>
                        <span class="ag-nav-text">Operación mayorista</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $mayMenuOpen ? 'open' : '' }}" id="sub-mayorista">
                        <li class="ag-sub-li">
                            <a href="{{ route('almacen-mayorista.index') }}" class="ag-sub-a {{ request()->routeIs('almacen-mayorista.index', 'almacen-mayorista.show', 'almacen-mayorista.create', 'almacen-mayorista.edit', 'almacen-mayorista.inventario.*') ? 'active' : '' }}">Almacenes</a>
                        </li>
                        @can('inventario.view')
                        <li class="ag-sub-li">
                            <a href="{{ route('almacen-mayorista.traslados-planta.index') }}" class="ag-sub-a {{ request()->routeIs('almacen-mayorista.traslados-planta.*') ? 'active' : '' }}">Recepciones de planta</a>
                        </li>
                        @endcan
                        @can('almacen.movimientos.view')
                        <li class="ag-sub-li">
                            <a href="{{ route('almacen-mayorista.movimientos.index') }}" class="ag-sub-a {{ request()->routeIs('almacen-mayorista.movimientos.*') && !request()->routeIs('almacen-mayorista.movimientos.reportes') ? 'active' : '' }}">Movimientos</a>
                        </li>
                        @endcan
                        @can('almacen.reportes.view')
                        <li class="ag-sub-li">
                            <a href="{{ route('almacen-mayorista.movimientos.reportes') }}" class="ag-sub-a {{ request()->routeIs('almacen-mayorista.movimientos.reportes') ? 'active' : '' }}">Reportes</a>
                        </li>
                        @endcan
                        @can('pedidos_distribucion.view')
                        <li class="ag-sub-li">
                            <a href="{{ route('punto-venta.pedidos.index', ['ctx' => 'mayorista']) }}" class="ag-sub-a {{ ($pedidosEnSeccionMayorista ?? false) ? 'active' : '' }}">Pedidos minoristas</a>
                        </li>
                        @endcan
                    </ul>
                </li>

                @if($esMayoristaOperativo && ! $isAdmin && ($puedeEnvios || $puedeSubmenuEnviosScoped))
                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $envMayOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-env-may">
                        <i class="ag-nav-icon fas fa-truck"></i>
                        <span class="ag-nav-text">Envíos</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $envMayOpen ? 'open' : '' }}" id="sub-env-may">
                        @include('layouts.partials.submenu-envios-items', ['envListadoActivo' => $envListadoActivo])
                        @include('layouts.partials.submenu-catalogos-logistica', ['catalogoSubId' => 'may'])
                    </ul>
                </li>
                @endif
                @endif

                @if($puedePuntoVentaMenu || $puedePedidosDistMenuPdv)
                <span class="ag-nav-label">Punto de venta</span>

                @if($esMinoristaOperativo || $isAdmin)
                <li class="ag-nav-li">
                    <a href="{{ route('dashboard.panel-minorista') }}" class="ag-nav-a {{ request()->routeIs('dashboard.panel-minorista') ? 'active' : '' }}">
                        <i class="ag-nav-icon fas fa-shopping-basket"></i>
                        <span class="ag-nav-text">Panel Minorista</span>
                    </a>
                </li>
                @endif

                <li class="ag-nav-li">
                    <a href="#" class="ag-nav-a {{ $pvMenuOpen ? 'active group-open' : '' }}" data-toggle-sub="sub-pv">
                        <i class="ag-nav-icon fas fa-store"></i>
                        <span class="ag-nav-text">Comercialización</span>
                        <i class="ag-nav-arrow fas fa-chevron-right"></i>
                    </a>
                    <ul class="ag-subnav {{ $pvMenuOpen ? 'open' : '' }}" id="sub-pv">
                        @if($puedePuntoVentaMenu)
                        <li class="ag-sub-li">
                            <a href="{{ route('punto-venta.puntos.index') }}" class="ag-sub-a {{ request()->routeIs('punto-venta.puntos.*') && !request()->routeIs('punto-venta.inventario.*') ? 'active' : '' }}">Puntos de venta</a>
                        </li>
                        <li class="ag-sub-li">
                            <a href="{{ route('punto-venta.inventario.index') }}" class="ag-sub-a {{ request()->routeIs('punto-venta.inventario.*') ? 'active' : '' }}">Inventario</a>
                        </li>
                        @endif
                        @if($puedePedidosDistMenuPdv)
                        <li class="ag-sub-li">
                            <a href="{{ route('punto-venta.pedidos.index', ['ctx' => 'pdv']) }}" class="ag-sub-a {{ ($pedidosEnSeccionPdv ?? false) ? 'active' : '' }}">Pedidos de distribución</a>
                        </li>
                        @endif
                        {{-- Planificar distribución: deshabilitado temporalmente --}}
                        @if(false && $isAdmin && \App\Support\UsuarioRol::puedePlanificarDistribucion($authUser))
                        <li class="ag-sub-li">
                            <a href="{{ route('punto-venta.rutas.index') }}" class="ag-sub-a {{ request()->routeIs('punto-venta.rutas.*') ? 'active' : '' }}">Planificar distribución</a>
                        </li>
                        @endif
                    </ul>
                </li>
                @endif
                @endif {{-- /TEMPORAL ! $isAdmin --}}

                @if(\App\Support\UsuarioRol::puedeGestionarUsuarios($authUser) || \App\Support\ReporteCatalogo::usuarioTieneAcceso(auth()->user()))
                <span class="ag-nav-label">{{ ($esJefeAgr || ($authUser && $authUser->hasRole('jefe_planta'))) && ! $isAdmin ? 'Equipo' : 'Administración' }}</span>

                @if($isAdmin && $pendientesSolicitudes > 0)
                <li class="ag-nav-li">
                    <a href="{{ route('gestion.index', ['estado' => 'pendiente']) }}" class="ag-nav-a {{ request('estado') === 'pendiente' ? 'active' : '' }}">
                        <i class="ag-nav-icon fas fa-user-clock"></i>
                        <span class="ag-nav-text">Solicitudes pendientes</span>
                        <span class="badge badge-warning ml-auto">{{ $pendientesSolicitudes }}</span>
                    </a>
                </li>
                @endif

                @if(\App\Support\UsuarioRol::puedeGestionarUsuarios($authUser))
                <li class="ag-nav-li">
                    <a href="{{ route('gestion.index') }}" class="ag-nav-a {{ request()->routeIs('gestion.*') ? 'active' : '' }}">
                        <i class="ag-nav-icon fas fa-users-cog"></i>
                        <span class="ag-nav-text">Gestión de usuarios</span>
                    </a>
                </li>
                @endif

                @if(\App\Support\ReporteCatalogo::usuarioTieneAcceso(auth()->user()))
                <li class="ag-nav-li">
                    <a href="{{ route('reportes.index') }}" class="ag-nav-a {{ request()->routeIs('reportes.*') ? 'active' : '' }}">
                        <i class="ag-nav-icon fas fa-chart-bar"></i>
                        <span class="ag-nav-text">Centro de reportes</span>
                    </a>
                </li>
                @endif
                @endif

            </ul>
        </nav>

        {{-- Footer --}}
        <div class="ag-sb-footer">
            <a href="{{ route('logout') }}" class="ag-sb-foot-btn ag-sb-foot-btn--link">
                <i class="fas fa-sign-out-alt" style="width:16px;text-align:center;"></i>
                <span>Cerrar sesión</span>
            </a>
        </div>

    </aside>
    {{-- END SIDEBAR --}}

    {{-- ═══════════════ MAIN ═══════════════ --}}
    <div class="ag-main" id="agMain">

        {{-- TOPBAR --}}
        <header class="ag-topbar">
            <button class="ag-tb-icon-btn" id="agToggle" title="Colapsar menú">
                <i class="fas fa-bars"></i>
            </button>

            <div class="ag-tb-breadcrumb">
                <span class="ag-tb-title">@yield('page_title', 'Dashboard')</span>
                @hasSection('breadcrumbs')
                    <span style="color:var(--border-2);margin:0 4px;">/</span>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            @yield('breadcrumbs')
                        </ol>
                    </nav>
                @endif
            </div>

            <div class="ag-tb-right">
                {{-- Fullscreen --}}
                <button class="ag-tb-icon-btn" id="agFullscreen" title="Pantalla completa">
                    <i class="fas fa-expand"></i>
                </button>

                {{-- User menu --}}
                <div class="ag-user-wrap">
                    <button class="ag-user-btn" id="agUserBtn">
                        <img src="{{ $userImg }}" alt="Avatar"
                             data-avatar-fallback="{{ $userImgFallback }}"
                             onerror="if(this.dataset.avatarFallback){this.onerror=null;this.src=this.dataset.avatarFallback;}">
                        <span class="ag-user-btn-name">{{ $userFull }}</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>

                    <div class="ag-dropdown" id="agUserDropdown">
                        <div class="ag-dd-header">
                            <span class="ag-dd-user-name">{{ $userFull }}</span>
                            <span class="ag-dd-user-role">
                                <span class="role-badge {{ $roleCss }}">{{ ucfirst($userRole) }}</span>
                            </span>
                        </div>
                        <div class="ag-dd-body">
                            <a href="{{ route('profile.show') }}" class="ag-dd-item">
                                <i class="fas fa-user-circle" style="width:14px;"></i> Mi perfil
                            </a>
                            <div class="ag-dd-sep"></div>
                            <a href="{{ route('logout') }}" class="ag-dd-item danger">
                                <i class="fas fa-sign-out-alt" style="width:14px;"></i> Cerrar sesión
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        {{-- END TOPBAR --}}

        {{-- CONTENT --}}
        <main class="ag-content">
            <div class="content-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h1 class="m-0">@yield('page_title', 'Dashboard')</h1>
                    </div>
                </div>
            </div>

            @include('partials.flash-messages')

            @yield('content')
        </main>

        @include('partials.modal-bienvenida')
        @include('partials.modal-aviso')
        @include('partials.modal-confirmar-accion')
        @include('partials.flash-error-modal')
        @include('partials.transportista-asignacion-login-modal')
        @include('partials.operario-planta-tarea-login-modal')
        @include('partials.agricultor-actividad-login-modal')
        @include('partials.jefe-agricultor-envio-login-modal')
        @include('partials.mayorista-pedido-login-modal')
        @include('partials.minorista-envio-login-modal')
        @include('partials.jefe-planta-traslado-login-modal')
        @include('partials.login-notificacion-mount')
        @once
            @include('partials.selector-catalogo-assets')
            @include('partials.selector-catalogo-modal')
            @include('partials.modal-vehiculo-carga-preview')
        @endonce

        {{-- FOOTER --}}
        <footer class="ag-footer">
            <span>&copy; {{ date('Y') }} <strong>AgroFusion</strong> · Sistema de Gestión Agrícola</span>
            <span>v1.0.0</span>
        </footer>

    </div>
    {{-- END MAIN --}}

</div>

<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
(function () {
    'use strict';

    var sidebar  = document.getElementById('agSidebar');
    var main     = document.getElementById('agMain');
    var toggle   = document.getElementById('agToggle');
    var overlay  = document.getElementById('agOverlay');
    var userBtn  = document.getElementById('agUserBtn');
    var userDrop = document.getElementById('agUserDropdown');
    var fullBtn  = document.getElementById('agFullscreen');

    // ── Sidebar toggle (desktop: mini / mobile: open) ─────────────────
    var isMobile = window.innerWidth <= 768;

    function refreshState() {
        isMobile = window.innerWidth <= 768;
    }

    window.addEventListener('resize', refreshState);

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (isMobile) {
                sidebar.classList.toggle('mobile-open');
                overlay.classList.toggle('show');
                main.classList.remove('mini');
            } else {
                var mini = sidebar.classList.toggle('mini');
                main.classList.toggle('mini', mini);
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('show');
        });
    }

    // ── Sub-menus ─────────────────────────────────────────────────────
    var sbNav = document.querySelector('.ag-sb-nav');
    var sbScrollKey = 'agSbNavScroll';

    if (sbNav) {
        function restoreSbScroll() {
            try {
                var savedScroll = sessionStorage.getItem(sbScrollKey);
                if (savedScroll !== null) {
                    sbNav.scrollTop = parseInt(savedScroll, 10) || 0;
                }
            } catch (err) {}
        }

        restoreSbScroll();
        requestAnimationFrame(function () {
            restoreSbScroll();
        });

        sbNav.addEventListener('scroll', function () {
            try { sessionStorage.setItem(sbScrollKey, String(sbNav.scrollTop)); } catch (err) {}
        }, { passive: true });

        sbNav.querySelectorAll('a[href]:not([href="#"]):not([href=""])').forEach(function (link) {
            if (link.getAttribute('href') === 'javascript:void(0)') return;
            link.addEventListener('click', function () {
                try { sessionStorage.setItem(sbScrollKey, String(sbNav.scrollTop)); } catch (err) {}
            });
        });
    }

    document.querySelectorAll('[data-toggle-sub]').forEach(function (trigger) {
        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var targetId = this.getAttribute('data-toggle-sub');
            var sub = document.getElementById(targetId);
            if (!sub) return;

            var navScrollBefore = sbNav ? sbNav.scrollTop : 0;
            var isOpen = sub.classList.contains('open');

            sub.classList.toggle('open', !isOpen);
            trigger.classList.toggle('group-open', !isOpen);

            if (sbNav) {
                sbNav.scrollTop = navScrollBefore;
            }
        });
    });

    // ── User dropdown ──────────────────────────────────────────────────
    if (userBtn && userDrop) {
        userBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            userDrop.classList.toggle('show');
        });

        document.addEventListener('click', function () {
            userDrop.classList.remove('show');
        });

        userDrop.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    }

    // ── Fullscreen ─────────────────────────────────────────────────────
    if (fullBtn) {
        fullBtn.addEventListener('click', function () {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen();
                fullBtn.querySelector('i').className = 'fas fa-compress';
            } else {
                document.exitFullscreen();
                fullBtn.querySelector('i').className = 'fas fa-expand';
            }
        });
    }

    // Borrador del wizard «Nuevo envío»: no debe sobrevivir al cerrar sesión
    document.querySelectorAll('a[href*="logout"]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const destino = link.getAttribute('href');
            if (!destino) return;
            try { sessionStorage.removeItem('agrofusion_envio_borrador_v1'); } catch (err) {}
            if (window.SimulacionRuta && typeof window.SimulacionRuta.marcarLogout === 'function') {
                window.SimulacionRuta.marcarLogout();
            } else {
                window.__agrofusionLoggingOut = true;
            }
            if (window.SimulacionMapaGlobal && typeof window.SimulacionMapaGlobal.destruir === 'function') {
                window.SimulacionMapaGlobal.destruir();
            }
            window.location.replace(destino);
        });
    });

})();
</script>

@include('partials.filtros-ui-script')

@stack('scripts')
</body>
</html>
