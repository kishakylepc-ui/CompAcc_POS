<?php

/*
|--------------------------------------------------------------------------
| MY ACCOUNT (moved)
|--------------------------------------------------------------------------
| My Account now lives in Settings > My Account, which every role can
| open. This address stays so old links and bookmarks keep working.
*/

require_once __DIR__
    . '/../../app/middleware/auth.php';

header('Location: /settings/?tab=account');
exit;
