<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/session.php";

destroy_user_session();

echo json_encode(["ok" => true]);
