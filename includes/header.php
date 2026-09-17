<?php
if (!isset($base_url)) {
    $script_file = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $app_root = str_replace('\\', '/', dirname(__DIR__));
    $script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    $app_len = strlen($app_root);
    if ($script_file && $script_name && stripos($script_file, $app_root) === 0) {
        $rel = ltrim(substr($script_file, $app_len), '/');
        $base_url = substr($script_name, 0, strlen($script_name) - strlen($rel));
    } else {
        $parts = explode('/', trim($script_name, '/'));
        $base_url = !empty($parts[0]) ? '/' . $parts[0] . '/' : '/';
    }
    if (substr($base_url, -1) !== '/') {
        $base_url .= '/';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= $page_title ?? 'Dashboard' ?> | Mehboob Traders</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <link rel="stylesheet" href="<?= $base_url ?? '' ?>assets/css/style.css?v=10">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body id="page-top">

<!-- Sidebar Overlay (mobile drawer backdrop) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div id="wrapper">

  <!-- ===== SIDEBAR ===== -->
  <nav class="sidebar" id="sidebar">

    <a class="sidebar-brand" href="<?= $base_url ?? '' ?>index.php">
      <div class="sidebar-brand-icon"><i class="fas fa-boxes"></i></div>
      <div>
        <span class="sidebar-brand-text">Mehboob Traders</span>
        <span class="sidebar-brand-sub">Wholesale</span>
      </div>
    </a>

    <hr class="sidebar-divider">

    <!-- Dashboard -->
    <div class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
      <a class="nav-link" href="<?= $base_url ?? '' ?>index.php">
        <i class="fas fa-fw fa-tachometer-alt"></i>
        <span>Dashboard</span>
      </a>
    </div>

    <hr class="sidebar-divider">

    <div class="sidebar-heading"><span>Management</span></div>

    <!-- Purchase (Admin only) -->
    <?php if (isAdmin()): ?>
    <div class="nav-item">
      <?php $on_purchase = str_contains($_SERVER['PHP_SELF'],'purchases/'); ?>
      <a class="nav-link <?= $on_purchase ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapsePurchase" role="button" aria-expanded="<?= $on_purchase ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-cart-arrow-down"></i>
        <span>Purchase</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_purchase ? 'show' : '' ?>" id="collapsePurchase">
        <div class="collapse-inner">
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'purchases/create') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/purchases/create.php"><i class="fas fa-plus-circle"></i> New Purchase</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'purchases/index') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/purchases/index.php"><i class="fas fa-list"></i> Purchase List</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Products (Admin: full | Sales team & Loader: view only) -->
    <div class="nav-item">
      <?php $on_product = str_contains($_SERVER['PHP_SELF'],'inventory/'); ?>
      <a class="nav-link <?= $on_product ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapseProduct" role="button" aria-expanded="<?= $on_product ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-box"></i>
        <span>Products</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_product ? 'show' : '' ?>" id="collapseProduct">
        <div class="collapse-inner">
          <?php if (isAdmin()): ?>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'inventory/product_create') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/inventory/product_create.php"><i class="fas fa-plus-circle"></i> Add Product</a>
          <?php endif; ?>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'inventory/products') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/inventory/products.php"><i class="fas fa-box"></i> Products List</a>
          <?php if (isAdmin()): ?>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'inventory/categor') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/inventory/categories.php"><i class="fas fa-tags"></i> Categories</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'inventory/brand') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/inventory/brands.php"><i class="fas fa-copyright"></i> Brands</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Suppliers (Admin only) -->
    <?php if (isAdmin()): ?>
    <div class="nav-item">
      <?php $on_supplier = str_contains($_SERVER['PHP_SELF'],'inventory/supplier') || str_contains($_SERVER['PHP_SELF'],'pay_supplier'); ?>
      <a class="nav-link <?= $on_supplier ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapseSupplier" role="button" aria-expanded="<?= $on_supplier ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-truck-loading"></i>
        <span>Suppliers</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_supplier ? 'show' : '' ?>" id="collapseSupplier">
        <div class="collapse-inner">
          <a class="collapse-item <?= (str_contains($_SERVER['PHP_SELF'],'inventory/suppliers') && isset($_GET['add'])) ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/inventory/suppliers.php?add=1"><i class="fas fa-plus-circle"></i> Add Supplier</a>
          <a class="collapse-item <?= (str_contains($_SERVER['PHP_SELF'],'inventory/suppliers') && !isset($_GET['add'])) ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/inventory/suppliers.php"><i class="fas fa-list"></i> View Suppliers</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'pay_supplier') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/transactions/pay_supplier.php"><i class="fas fa-money-bill-wave"></i> Pay Amount</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Customers (Admin + Sales team) -->
    <?php if (isAdmin() || isSalesTeam()): ?>
    <div class="nav-item">
      <?php $on_customer = str_contains($_SERVER['PHP_SELF'],'customers/') || str_contains($_SERVER['PHP_SELF'],'receive_customer'); ?>
      <a class="nav-link <?= $on_customer ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapseCustomer" role="button" aria-expanded="<?= $on_customer ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-users"></i>
        <span>Customers</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_customer ? 'show' : '' ?>" id="collapseCustomer">
        <div class="collapse-inner">
          <?php if (isAdmin() || isSalesTeam()): ?>
          <a class="collapse-item <?= (str_contains($_SERVER['PHP_SELF'],'customers/customers') && isset($_GET['add'])) ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/customers/customers.php?add=1"><i class="fas fa-user-plus"></i> Add Customer</a>
          <?php endif; ?>
          <a class="collapse-item <?= (str_contains($_SERVER['PHP_SELF'],'customers/customers') && !isset($_GET['add'])) ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/customers/customers.php"><i class="fas fa-address-card"></i> View Customers</a>
          <?php if (isAdmin()): ?>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'receive_customer') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/transactions/receive_customer.php"><i class="fas fa-hand-holding-usd"></i> Pay Amount</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Areas & Territories (Admin only) -->
    <?php if (isAdmin()): ?>
    <div class="nav-item">
      <a class="nav-link <?= str_contains($_SERVER['PHP_SELF'],'areas/') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/areas/index.php">
        <i class="fas fa-fw fa-map-marked-alt"></i>
        <span>Areas</span>
      </a>
    </div>
    <?php endif; ?>

    <!-- Employees (Admin only) -->
    <?php if (isAdmin()): ?>
    <div class="nav-item">
      <?php $on_employee = str_contains($_SERVER['PHP_SELF'],'employees/'); ?>
      <a class="nav-link <?= $on_employee ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapseEmployee" role="button" aria-expanded="<?= $on_employee ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-user-tie"></i>
        <span>Employees</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_employee ? 'show' : '' ?>" id="collapseEmployee">
        <div class="collapse-inner">
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'employees/create') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/employees/create.php"><i class="fas fa-user-plus"></i> Add Employee</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'employees/index') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/employees/index.php"><i class="fas fa-list"></i> View Employees</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'employees/salary_ledger') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/employees/salary_ledger.php"><i class="fas fa-book"></i> Salary Ledger</a>
          <a class="collapse-item <?= (str_contains($_SERVER['PHP_SELF'],'employees/salary') && !str_contains($_SERVER['PHP_SELF'],'salary_ledger')) ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/employees/salary.php"><i class="fas fa-money-check-alt"></i> Pay Salary</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <hr class="sidebar-divider">

    <div class="sidebar-heading"><span>Finance</span></div>

    <!-- Sales (Admin + Sales team) -->
    <?php if (isAdmin() || isSalesTeam()): ?>
    <div class="nav-item">
      <?php $on_sales = str_contains($_SERVER['PHP_SELF'],'sales/') && !str_contains($_SERVER['PHP_SELF'],'sales/dsr'); ?>
      <a class="nav-link <?= $on_sales ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapseSales" role="button" aria-expanded="<?= $on_sales ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-shopping-cart"></i>
        <span>Sales</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_sales ? 'show' : '' ?>" id="collapseSales">
        <div class="collapse-inner">
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'sales/index') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/sales/index.php"><i class="fas fa-clipboard-check"></i> Take Order</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'sales/invoices.php') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/sales/invoices.php"><i class="fas fa-file-invoice"></i> Invoices</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'sales/order_booker_invoices') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/sales/order_booker_invoices.php"><i class="fas fa-user-tag"></i> Order Booker Invoices</a>
          <a class="collapse-item <?= str_contains($_SERVER['PHP_SELF'],'sales/packlist') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/sales/packlist.php"><i class="fas fa-truck-loading"></i> Delivery List</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (isAdmin()): ?>
    <!-- Cash Book -->
    <div class="nav-item">
      <a class="nav-link <?= str_contains($_SERVER['PHP_SELF'],'cashbook') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/cashbook/index.php">
        <i class="fas fa-fw fa-money-bill-wave"></i>
        <span>Cash Book</span>
      </a>
    </div>

    <!-- Bank Book -->
    <div class="nav-item">
      <a class="nav-link <?= str_contains($_SERVER['PHP_SELF'],'bankbook') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/bankbook/index.php">
        <i class="fas fa-fw fa-university"></i>
        <span>Bank Book</span>
      </a>
    </div>

    <!-- Expenses -->
    <div class="nav-item">
      <?php $on_expense = str_contains($_SERVER['PHP_SELF'],'expenses'); ?>
      <a class="nav-link <?= $on_expense ? '' : 'collapsed' ?>" data-toggle="collapse" href="#collapseExpense" role="button" aria-expanded="<?= $on_expense ? 'true' : 'false' ?>">
        <i class="fas fa-fw fa-file-invoice-dollar"></i>
        <span>Expenses</span>
        <span class="arrow"><i class="fas fa-chevron-right"></i></span>
      </a>
      <div class="collapse <?= $on_expense ? 'show' : '' ?>" id="collapseExpense">
        <div class="collapse-inner">
          <a class="collapse-item" href="<?= $base_url ?? '' ?>modules/expenses/index.php"><i class="fas fa-file-invoice-dollar"></i> All Expenses</a>
          <a class="collapse-item" href="<?= $base_url ?? '' ?>modules/expenses/categories.php"><i class="fas fa-tags"></i> Categories</a>
        </div>
      </div>
    </div>

    <?php endif; ?>

    <!-- Others (Admin + Sales team) -->
    <?php if (isAdmin() || isSalesTeam()): ?>
    <hr class="sidebar-divider">

    <div class="sidebar-heading"><span>Others</span></div>

    <!-- Daily Sales Report -->
    <div class="nav-item">
      <a class="nav-link <?= str_contains($_SERVER['PHP_SELF'],'sales/dsr') ? 'active' : '' ?>" href="<?= $base_url ?? '' ?>modules/sales/dsr.php">
        <i class="fas fa-fw fa-calendar-day"></i>
        <span>Daily Sales Report</span>
      </a>
    </div>
    <?php endif; ?>

    <!-- Sidebar Footer (gym-style) -->
    <div class="sidebar-footer">
      <a class="nav-link" href="<?= $base_url ?? '' ?>login.php?logout=1"><i class="fas fa-fw fa-sign-out-alt"></i> <span>Logout</span></a>
    </div>
  </nav>
  <!-- End Sidebar -->

  <!-- ===== CONTENT WRAPPER ===== -->
  <div id="content-wrapper">

    <!-- Topbar -->
    <header class="topbar">
      <button class="sidebar-toggle-btn" id="sidebarMobileToggle">
        <i class="fas fa-bars"></i>
      </button>
      <div class="page-title"><?= $page_title ?? 'Dashboard' ?></div>
      <div class="user-area">
        <span class="badge <?= isAdmin() ? 'badge-success' : 'badge-info' ?> d-none d-md-inline"><?= roleLabel($user_role) ?></span>
        <div class="dropdown">
          <button class="btn btn-link text-muted dropdown-toggle p-0" data-toggle="dropdown">
            <i class="fas fa-user-circle fa-lg"></i>
            <span class="ml-1 d-none d-sm-inline"><?= $_SESSION['user_name'] ?? 'Admin' ?></span>
          </button>
          <div class="dropdown-menu dropdown-menu-right">
            <a class="dropdown-item" href="<?= $base_url ?? '' ?>modules/profile/index.php">
              <i class="fas fa-user-circle fa-sm mr-2"></i> My Profile
            </a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item" href="<?= $base_url ?? '' ?>login.php?logout=1">
              <i class="fas fa-sign-out-alt fa-sm mr-2 text-danger"></i> Logout
            </a>
          </div>
        </div>
      </div>
    </header>

    <!-- Page Content -->
    <div class="content">

      <!-- Page Heading -->
      <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#0f172a;"><?= $page_title ?? 'Dashboard' ?></h1>
      </div>

      <!-- Flash Messages -->
      <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= $_SESSION['success']; unset($_SESSION['success']); ?>
          <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
      <?php endif; ?>
      <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= $_SESSION['error']; unset($_SESSION['error']); ?>
          <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
      <?php endif; ?>