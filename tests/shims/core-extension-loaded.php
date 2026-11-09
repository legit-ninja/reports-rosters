<?php

namespace InterSoccer\ReportsRosters\Core;

function extension_loaded($extension) {
    if (isset($GLOBALS['intersoccer_test_loaded_extensions']) && is_array($GLOBALS['intersoccer_test_loaded_extensions'])) {
        return !empty($GLOBALS['intersoccer_test_loaded_extensions'][$extension]);
    }

    return \extension_loaded($extension);
}
