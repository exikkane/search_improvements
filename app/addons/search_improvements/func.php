<?php

use Tygh\Registry;

function fn_search_improvements_additional_fields_in_search(
    $params, $fields, $sortings, $condition, $join, $sorting, $group_by, &$tmp, $piece, $having, $lang_code
)
{
    if (empty($params['q'])) {
        return;
    }

    $query = trim($params['q']);

    $c_id = db_get_fields("
        SELECT company_id FROM ?:companies
        WHERE SOUNDEX(company) = SOUNDEX(?s)
        OR company LIKE ?l",
        $query, "%$query%"
    );

    if (!empty($c_id)) {
        $tmp .= db_quote(' OR products.company_id IN (?n)', $c_id);
    }

    $brand_feature_id = Registry::get('addons.search_improvements.brand_feature_id');

    // Search by Feature Variant
    $f_id = db_get_fields("
         SELECT pfvd.variant_id FROM ?:product_feature_variant_descriptions as pfvd
         LEFT JOIN ?:product_feature_variants as pfv
         ON pfv.variant_id = pfvd.variant_id
         WHERE pfvd.variant LIKE ?l
         AND pfv.feature_id = ?i",
        $query, "%$query%", $brand_feature_id
    );

    $keywords = preg_split('/\s+/', trim($query));
    $like_clauses = [];
    $like_params = [];

    foreach ($keywords as $word) {
        if (strlen($word) > 2) { // skip short/common words
            $like_clauses[] = "pfvd.variant LIKE ?l";
            $like_params[] = "%$word%";
        }
    }

    $where_like = implode(' OR ', $like_clauses);

    $sql = "
        SELECT pfvd.variant_id
        FROM ?:product_feature_variant_descriptions AS pfvd
        LEFT JOIN ?:product_feature_variants AS pfv
            ON pfv.variant_id = pfvd.variant_id
        WHERE ($where_like)
        AND pfv.feature_id = ?i
    ";
    $like_params[] = $brand_feature_id;

    $f_id = db_get_fields($sql, ...$like_params);

    if (!empty($f_id)) {
        $product_ids = db_get_fields("
            SELECT product_id FROM ?:product_features_values
            WHERE lang_code = 'en'
            AND variant_id IN (?n)",
            $f_id
        );

        if (!empty($product_ids)) {
            $tmp .= db_quote(' OR products.product_id IN (?n)', $product_ids);
        }
    }
}
