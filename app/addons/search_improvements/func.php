<?php

use Tygh\Registry;

function sort_variants_by_query(array $variants, string $query): array {
    $keywords = preg_split('/\s+/', mb_strtolower(trim($query)));

    usort($variants, function ($a, $b) use ($keywords) {
        return relevance_score($b['variant'] ?? $b['product'], $keywords) <=> relevance_score($a['variant'] ?? $a['product'], $keywords);
    });

    return $variants;
}

function relevance_score(string $text, array $keywords): int {
    $text = mb_strtolower($text);
    $score = 0;

    foreach ($keywords as $keyword) {
        if ($text === $keyword) {
            $score += 200;
        }

        $pos = mb_stripos($text, $keyword);
        if ($pos !== false) {
            $score += 100 - $pos;
        }
    }

    return $score;
}

function fn_search_improvements_get_products_before_select(&$params, &$join, &$condition, &$u_condition, &$inventory_condition, &$sortings, &$total, &$items_per_page, &$lang_code, &$having)
{
    if (!empty($_REQUEST['search_performed']) && empty($_REQUEST['sort_by'])) {
        $params['sort_by'] = 'relevance';
        $params['sort_order'] = 'desc';
    }
}

function fn_search_improvements_additional_fields_in_search(
    &$params, $fields, &$sortings, $condition, $join, $sorting, $group_by, &$tmp, $piece, $having, $lang_code
)
{
    if (empty($params['q'])) {
        return;
    }

    $query = trim($params['q']);

    // --- Company name matching ---
    $c_id = db_get_fields("
        SELECT company_id
        FROM ?:companies
        WHERE SOUNDEX(company) = SOUNDEX(?s)
           OR company LIKE ?l
        ORDER BY
            CASE
                WHEN SOUNDEX(company) = SOUNDEX(?s) THEN 10
                WHEN company LIKE ?l THEN 5
                ELSE 0
            END DESC
    ",
        $query, "%$query%", $query, "%$query%"
    );

    if (!empty($c_id)) {
        $tmp .= db_quote(' OR products.company_id IN (?n)', $c_id);
        $sortings['relevance'] = "FIELD(products.company_id, '" . join("','", $c_id) . "')";
    }

    // --- Feature variant (e.g., brand) matching ---
    $brand_feature_id = Registry::get('addons.search_improvements.brand_feature_id');


    $f_id = db_get_array("SELECT DISTINCT pfvd.variant_id, pfvd.variant
        FROM ?:product_feature_variant_descriptions AS pfvd
        LEFT JOIN ?:product_feature_variants AS pfv
            ON pfv.variant_id = pfvd.variant_id
        WHERE pfvd.variant LIKE ?s
        AND pfv.feature_id = ?i
    ", "%$query%", $brand_feature_id);

    $sorted = sort_variants_by_query($f_id, $query);

    if (!empty($f_id)) {
        $v_ids = array_column($sorted, 'variant_id');
        $product_ids = db_get_fields("
            SELECT product_id
            FROM ?:product_features_values
            WHERE lang_code = ?s
            AND variant_id IN (?n)
            ORDER BY product_id desc
        ", 'en', $v_ids, $v_ids);

        if (!empty($product_ids)) {
            $sortings['relevance'] = "FIELD(products.product_id, '" . join("','", $product_ids) . "')";
            $tmp .= db_quote(' OR products.product_id IN (?n)', $product_ids);
        }
    }

    $product_name_matches = db_get_array("
        SELECT product_id, product
        FROM ?:product_descriptions
        WHERE lang_code = ?s
        AND product LIKE ?l
    ", $lang_code, "%$query%");

    if (!empty($product_name_matches)) {
        $sorted_by_name = sort_variants_by_query($product_name_matches, $query);
        $product_ids_by_name = array_column($sorted_by_name, 'product_id');

        // Combine with any existing relevance logic
        if (!empty($sortings['relevance'])) {
            $existing_order = trim($sortings['relevance'], "FIELD(products.product_id, ')(");
            $combined = array_unique(array_merge(explode("','", $existing_order), $product_ids_by_name));
        } else {
            $combined = $product_ids_by_name;
        }

        $sortings['relevance'] = "FIELD(products.product_id, '" . join("','", $combined) . "')";
        $tmp .= db_quote(' OR products.product_id IN (?n)', $product_ids_by_name);
    }
}
