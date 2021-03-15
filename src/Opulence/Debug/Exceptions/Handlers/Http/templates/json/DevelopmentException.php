<?php

/*
 * Opulence
 *
 * @link      https://www.opulencephp.com
 * @copyright Copyright (C) 2021 David Young
 * @license   https://github.com/opulencephp/Opulence/blob/1.2/LICENSE.md
 */

$messages = [$ex->getMessage()];

while ($ex = $ex->getPrevious()) {
    $messages[] = $ex->getMessage();
}

echo json_encode([
    'error' => [
        'code' => $statusCode,
        'message' => implode("\n", $messages)
    ]
]);
