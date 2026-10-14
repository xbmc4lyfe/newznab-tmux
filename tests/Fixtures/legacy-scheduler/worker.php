<?php

declare(strict_types=1);

$db = new PDO('sqlite:/state/work.sqlite');
$id = bin2hex(random_bytes(8));
file_put_contents('/state/admitted', $id."\n", FILE_APPEND | LOCK_EX);
file_put_contents('/state/worker.ready', '1');
while (! file_exists('/state/release')) {
    usleep(10000);
}
$db->beginTransaction();
$db->prepare('INSERT INTO completed (id) VALUES (?)')->execute([$id]);
$db->commit();
