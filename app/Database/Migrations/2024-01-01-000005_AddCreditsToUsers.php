<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCreditsToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'credits' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
                'null'       => false,
                'after'      => 'city',
            ],
            'free_auctions_used' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
                'null'       => false,
                'after'      => 'credits',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', ['credits', 'free_auctions_used']);
    }
}