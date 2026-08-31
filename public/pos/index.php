<?php

require_once __DIR__
    . '/../../app/middleware/role.php';

requireRole([
    'Admin',
    'Manager',
    'Cashier'
]);

$pageTitle = 'Point of Sale';
$currentPage = 'pos';

require_once __DIR__
    . '/../../app/views/partials/header.php';

require_once __DIR__
    . '/../../app/views/partials/sidebar.php';

?>

<div class="card" style="padding: 30px;">

    <h2>Point of Sale</h2>

    <p>
        The POS transaction interface will go here.
    </p>

</div>

<?php

require_once __DIR__
    . '/../../app/views/partials/footer.php';

?>