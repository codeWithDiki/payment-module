<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payment_method_groups', function (Blueprint $table) {
            $table->id();
            $table->string("name");
            $table->string("slug");
            $table->string("image_url")->nullable();
            $table->boolean("is_active")->default(false);

            $table->timestamps();
        });


        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(config("payment-module.payment_method_group_class"))->nullable();
            $table->string("name");
            $table->string("vendor");
            $table->string("channel");
            $table->string("description")->nullable();
            $table->string("image_url")->nullable();
            $table->longText("meta_data")->nullable();
            $table->boolean("is_active")->default(false);

            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(config('payment-module.payment_method_class'));
            $table->morphs("paymentable");
            $table->string("payment_code")->unique();
            $table->double("amount");
            $table->longText("payment_headers")->nullable();
            $table->longText("payment_payload")->nullable();
            $table->longText("payment_response")->nullable();
            $table->string("customer_name")->nullable();
            $table->string("customer_email")->nullable();
            $table->string("customer_phone")->nullable();
            $table->string("customer_address")->nullable();
            $table->longText("customer_custom_data")->nullable();
            $table->string("status");
            $table->dateTime('paid_at')->nullable();

            $table->timestamps();
        });


    }

    public function down()
    {
        Schema::dropIfExists("payments");
        Schema::dropIfExists("payment_methods");
        Schema::dropIfExists("payment_method_groups");
    }
};
