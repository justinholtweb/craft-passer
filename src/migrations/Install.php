<?php

namespace justinholtweb\passer\migrations;

use craft\db\Migration;
use justinholtweb\passer\records\LogRecord;
use justinholtweb\passer\records\MapRecord;
use justinholtweb\passer\records\PlanRecord;
use justinholtweb\passer\records\RunRecord;

/**
 * Creates Passer's four tables: plans, runs, the ID map and the per-record log.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createPlansTable();
        $this->createRunsTable();
        $this->createMapTable();
        $this->createLogTable();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(LogRecord::TABLE);
        $this->dropTableIfExists(MapRecord::TABLE);
        $this->dropTableIfExists(RunRecord::TABLE);
        $this->dropTableIfExists(PlanRecord::TABLE);

        return true;
    }

    private function createPlansTable(): void
    {
        if ($this->db->tableExists(PlanRecord::TABLE)) {
            return;
        }

        $this->createTable(PlanRecord::TABLE, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'sourceType' => $this->string(32)->notNull(),
            // Credentials are stored encrypted by the service layer, never in the clear.
            'sourceConfig' => $this->text(),
            'config' => $this->longText(),
            'inventory' => $this->longText(),
            'userId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, PlanRecord::TABLE, ['sourceType']);
    }

    private function createRunsTable(): void
    {
        if ($this->db->tableExists(RunRecord::TABLE)) {
            return;
        }

        $this->createTable(RunRecord::TABLE, [
            'id' => $this->primaryKey(),
            'planId' => $this->integer(),
            'name' => $this->string()->notNull(),
            'sourceType' => $this->string(32)->notNull(),
            'sourceConfig' => $this->text(),
            'status' => $this->string(16)->notNull()->defaultValue(RunRecord::STATUS_PENDING),
            'phase' => $this->string(32),
            'totals' => $this->text(),
            // Where the run got to, per phase, so a paused or crashed run resumes rather than restarts.
            'checkpoint' => $this->text(),
            'report' => $this->longText(),
            'dryRun' => $this->boolean()->notNull()->defaultValue(false),
            'userId' => $this->integer(),
            'dateStarted' => $this->dateTime(),
            'dateFinished' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, RunRecord::TABLE, ['status']);
        $this->createIndex(null, RunRecord::TABLE, ['planId']);
    }

    private function createMapTable(): void
    {
        if ($this->db->tableExists(MapRecord::TABLE)) {
            return;
        }

        $this->createTable(MapRecord::TABLE, [
            'id' => $this->primaryKey(),
            // Identifies the WordPress install, so two migrations into one Craft site don't collide.
            'sourceHash' => $this->string(40)->notNull(),
            'sourceKey' => $this->string(64)->notNull(),
            'sourceId' => $this->string(64)->notNull(),
            'destType' => $this->string(255)->notNull(),
            'destId' => $this->integer(),
            'destUid' => $this->char(36),
            'siteId' => $this->integer(),
            // Hash of the source payload, so an unchanged record can be skipped on a re-run.
            'contentHash' => $this->char(40),
            'sourceUrl' => $this->text(),
            'runId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(
            null,
            MapRecord::TABLE,
            ['sourceHash', 'sourceKey', 'sourceId', 'siteId'],
            true
        );
        $this->createIndex(null, MapRecord::TABLE, ['destType', 'destId']);
        $this->createIndex(null, MapRecord::TABLE, ['runId']);
    }

    private function createLogTable(): void
    {
        if ($this->db->tableExists(LogRecord::TABLE)) {
            return;
        }

        $this->createTable(LogRecord::TABLE, [
            'id' => $this->primaryKey(),
            'runId' => $this->integer()->notNull(),
            'level' => $this->string(16)->notNull()->defaultValue(LogRecord::LEVEL_INFO),
            'phase' => $this->string(32),
            'sourceKey' => $this->string(64),
            'sourceId' => $this->string(64),
            'sourceTitle' => $this->string(255),
            'message' => $this->text()->notNull(),
            'context' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, LogRecord::TABLE, ['runId', 'level']);
        $this->createIndex(null, LogRecord::TABLE, ['runId', 'phase']);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, PlanRecord::TABLE, ['userId'], '{{%users}}', ['id'], 'SET NULL', null);
        $this->addForeignKey(null, RunRecord::TABLE, ['userId'], '{{%users}}', ['id'], 'SET NULL', null);
        $this->addForeignKey(null, RunRecord::TABLE, ['planId'], PlanRecord::TABLE, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, LogRecord::TABLE, ['runId'], RunRecord::TABLE, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, MapRecord::TABLE, ['siteId'], '{{%sites}}', ['id'], 'CASCADE', null);
        // The map deliberately has no FK to runs — it must survive the run that created it.
    }
}
