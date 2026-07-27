<?php

namespace RideSync\Backend\Repositories;

use mysqli;
use RideSync\Backend\Contracts\RepositoryContract;

abstract class BaseRepository implements RepositoryContract
{
    public function __construct(protected mysqli $conn)
    {
    }

    protected function tableExists(string $table): bool
    {
        if (function_exists('ridesync_table_exists')) {
            return ridesync_table_exists($this->conn, $table);
        }

        $safeTable = mysqli_real_escape_string($this->conn, $table);
        $result = mysqli_query($this->conn, "SHOW TABLES LIKE '{$safeTable}'");
        return $result && mysqli_num_rows($result) > 0;
    }
}
