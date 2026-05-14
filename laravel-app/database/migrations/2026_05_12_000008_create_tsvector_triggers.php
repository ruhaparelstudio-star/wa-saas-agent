<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Function + trigger for faqs
        DB::unprepared("
            CREATE OR REPLACE FUNCTION update_faq_search_vector()
            RETURNS TRIGGER AS \$\$
            BEGIN
                NEW.search_vector := to_tsvector('simple',
                    coalesce(NEW.question, '') || ' ' || coalesce(NEW.answer, '')
                );
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::unprepared("
            DROP TRIGGER IF EXISTS faqs_search_vector_trigger ON faqs;
            CREATE TRIGGER faqs_search_vector_trigger
                BEFORE INSERT OR UPDATE ON faqs
                FOR EACH ROW EXECUTE FUNCTION update_faq_search_vector();
        ");

        // Function + trigger for knowledge_items
        DB::unprepared("
            CREATE OR REPLACE FUNCTION update_knowledge_item_search_vector()
            RETURNS TRIGGER AS \$\$
            BEGIN
                NEW.search_vector := to_tsvector('simple',
                    coalesce(NEW.title, '') || ' ' ||
                    coalesce(NEW.content, '') || ' ' ||
                    coalesce(NEW.category, '')
                );
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::unprepared("
            DROP TRIGGER IF EXISTS knowledge_items_search_vector_trigger ON knowledge_items;
            CREATE TRIGGER knowledge_items_search_vector_trigger
                BEFORE INSERT OR UPDATE ON knowledge_items
                FOR EACH ROW EXECUTE FUNCTION update_knowledge_item_search_vector();
        ");

        // Backfill existing rows
        DB::unprepared("
            UPDATE faqs
            SET search_vector = to_tsvector('simple',
                coalesce(question, '') || ' ' || coalesce(answer, '')
            );
        ");

        DB::unprepared("
            UPDATE knowledge_items
            SET search_vector = to_tsvector('simple',
                coalesce(title, '') || ' ' || coalesce(content, '') || ' ' || coalesce(category, '')
            );
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS faqs_search_vector_trigger ON faqs;');
        DB::unprepared('DROP TRIGGER IF EXISTS knowledge_items_search_vector_trigger ON knowledge_items;');
        DB::unprepared('DROP FUNCTION IF EXISTS update_faq_search_vector();');
        DB::unprepared('DROP FUNCTION IF EXISTS update_knowledge_item_search_vector();');
    }
};
