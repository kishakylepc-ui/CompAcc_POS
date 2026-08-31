<?php

require_once __DIR__
    . '/../../app/middleware/role.php';

requireRole([
    'Admin',
    'Manager'
]);

$pageTitle = 'Inventory';
$currentPage = 'inventory';

require_once __DIR__
    . '/../../app/views/partials/header.php';

require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>

<div class="card" style="padding: 30px;">

    <h2>Inventory Management</h2>

    <p>
        Products and inventory will be managed here.
    </p>

</div>

<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>