<?php

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

class CreateQuickReplyTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quick_reply', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title', 100)->comment('标题');
            $table->text('content')->comment('内容');
            $table->integer('sort_order')->default(0)->comment('排序');
            $table->tinyInteger('is_active')->default(1)->comment('是否启用');
            $table->timestamps();
            
            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quick_reply');
    }
}

