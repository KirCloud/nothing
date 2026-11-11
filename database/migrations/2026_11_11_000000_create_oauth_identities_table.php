<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOauthIdentitiesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_oauth_identity')) return;
        Schema::create('v2_oauth_identity', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->string('provider', 32)->charset('ascii')->collation('ascii_bin');
            $table->string('provider_id', 255)->charset('ascii')->collation('ascii_bin');
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('updated_at')->nullable();
            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_oauth_identity');
    }
}
