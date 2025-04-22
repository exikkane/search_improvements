<?php

use Tygh\Registry;

function sort_variants_by_query(array $variants, string $query): array {
    $keywords = preg_split('/\s+/', mb_strtolower(trim($query)));

    usort($variants, function ($a, $b) use ($keywords) {
        return relevance_score($b['variant'], $keywords) <=> relevance_score($a['variant'], $keywords);
    });

    return $variants;
}

function relevance_score(string $text, array $keywords): int {
    $text = mb_strtolower($text);
    $score = 0;

    foreach ($keywords as $keyword) {
        $pos = mb_stripos($text, $keyword);
        if ($pos !== false) {
            $score += 100 - $pos; // earlier match = higher score
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

    $brand_feature_id = Registry::get('addons.search_improvements.brand_feature_id');

    $keywords = preg_split('/\s+/', trim($query));
    $like_clauses = [];
    $like_params = [];

    foreach ($keywords as $word) {
        if (strlen($word) > 2) { // skip short/common words
            $like_clauses[] = "pfvd.variant LIKE ?l";
            $like_params[] = "%$word%";
        }
    }

    $where_like = !empty(implode(' OR ', $like_clauses)) ? implode(' OR ', $like_clauses) : 1;

    $sql = "
    SELECT DISTINCT pfvd.variant_id, pfvd.variant
    FROM ?:product_feature_variant_descriptions AS pfvd
    LEFT JOIN ?:product_feature_variants AS pfv
        ON pfv.variant_id = pfvd.variant_id
    WHERE ($where_like)
    AND pfv.feature_id = $brand_feature_id
";

    $f_id = db_get_array($sql, ...$like_params);
    $sorted = sort_variants_by_query($f_id, $query);
    if (!empty($f_id)) {
    $v_ids = array_column($sorted, 'variant_id');
        $product_ids = db_get_fields("
    SELECT product_id
    FROM ?:product_features_values
    WHERE lang_code = 'en'
    AND variant_id IN (?n)
    ORDER BY FIELD(variant_id, ?a)",
            $v_ids, $v_ids
        );

        if (!empty($product_ids)) {
            $sortings['relevance'] = "FIELD(products.product_id, '" . join("','", $product_ids) . "')";
            $tmp .= db_quote(' OR products.product_id IN (?n)', $product_ids);
        }
    }

}
