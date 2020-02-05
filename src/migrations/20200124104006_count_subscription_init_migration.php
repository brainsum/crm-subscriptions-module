<?php

use Phinx\Migration\AbstractMigration;

class CountSubscriptionInitMigration extends AbstractMigration
{

    public function up()
    {
        $table = $this->table('subscriptions');

        $exists = $table->hasColumn('articles');

        if (!$exists) {
            $table->addColumn('articles', 'blob', ['null' => true, 'default' => NULL])->update();
        }
    }

    public function down() {
        $table = $this->table('subscriptions');

        $exists = $table->hasColumn('articles');

        if ($exists) {
            $table->removeColumn('articles')->save();
        }
    }

}
