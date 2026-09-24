<?php

// Advanced personalization for the existing Algorithm Control feature. Every
// entry point is optional so a rolling code deployment keeps the legacy scorer
// operational until the additive SQL migration has been applied.

function Wo_RamzaAlgorithmConfigValue($key, $default = '') {
    global $wo;
    if (isset($wo['config'][$key]) && $wo['config'][$key] !== '') {
        return (string)$wo['config'][$key];
    }
    return (string)$default;
}

function Wo_RamzaAlgorithmConfigNumber($key, $default = 0) {
    global $wo;
    if (isset($wo['config'][$key]) && is_numeric($wo['config'][$key])) {
        return (float)$wo['config'][$key];
    }
    return (float)$default;
}

function Wo_RamzaAlgorithmConfigSwitch($key, $default = '0') {
    global $wo;
    $val = isset($wo['config'][$key]) ? (string)$wo['config'][$key] : (string)$default;
    return in_array(strtolower(trim($val)), array('1', 'true', 'on', 'yes'), true);
}

function Wo_RamzaAlgorithmTableExists($table) {
    global $sqlConnect;
    static $cache = array();
    $table = (string)$table;
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    if (empty($sqlConnect) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $escaped = mysqli_real_escape_string($sqlConnect, $table);
    $query = @mysqli_query($sqlConnect, "SHOW TABLES LIKE '{$escaped}'");
    if (!$query || mysqli_num_rows($query) < 1) {
        $cache[$table] = false;
        return false;
    }
    // Windows MariaDB commonly runs with lower_case_table_names enabled and
    // returns the normalized physical name. The identifier is already limited
    // to alphanumerics/underscores, so compare the exact logical name without
    // making availability depend on filesystem case semantics.
    $cache[$table] = false;
    while ($row = mysqli_fetch_row($query)) {
        if (!empty($row[0]) && strcasecmp((string)$row[0], $table) === 0) {
            $cache[$table] = true;
            break;
        }
    }
    return $cache[$table];
}

function Wo_RamzaAlgorithmAdvancedAvailable() {
    if (!Wo_RamzaAlgorithmConfigSwitch('algorithm_advanced_topics', '1')) {
        return false;
    }
    return Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_TOPICS)
        && Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_POST_TOPICS)
        && Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_USER_INTERESTS);
}

function Wo_RamzaAlgorithmNormalizeText($value) {
    $value = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/https?:\/\/\S+/u', ' ', $value);
    $value = preg_replace('/[^\pL\pN#@]+/u', ' ', $value);
    return trim(preg_replace('/\s+/u', ' ', $value));
}

function Wo_RamzaAlgorithmTopicDictionary() {
    global $sqlConnect;
    static $dictionary = null;
    if ($dictionary !== null) {
        return $dictionary;
    }
    $dictionary = array();
    if (!Wo_RamzaAlgorithmAdvancedAvailable()) {
        return $dictionary;
    }
    $query = @mysqli_query($sqlConnect, "SELECT `id`,`slug`,`name`,`topic_type`,`language` FROM " . T_RAMZA_ALGORITHM_TOPICS . " WHERE `status` = 1 ORDER BY `post_count` DESC, `id` ASC LIMIT 2500");
    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $id = (int)$row['id'];
            $terms = array($row['slug'], str_replace('-', ' ', $row['slug']), $row['name']);
            foreach ($terms as $term) {
                $normalized = Wo_RamzaAlgorithmNormalizeText($term);
                if ($normalized !== '') {
                    $dictionary[$normalized][$id] = array('confidence' => 0.82, 'source' => $row['topic_type']);
                }
            }
        }
    }
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_TOPIC_ALIASES)) {
        $query = @mysqli_query($sqlConnect, "SELECT `topic_id`,`normalized_alias` FROM " . T_RAMZA_ALGORITHM_TOPIC_ALIASES . " ORDER BY `id` ASC LIMIT 5000");
        if ($query) {
            while ($row = mysqli_fetch_assoc($query)) {
                $term = Wo_RamzaAlgorithmNormalizeText($row['normalized_alias']);
                if ($term !== '') {
                    $dictionary[$term][(int)$row['topic_id']] = array('confidence' => 0.70, 'source' => 'alias');
                }
            }
        }
    }
    return $dictionary;
}

function Wo_RamzaAlgorithmEnsureHashtagTopics($text) {
    global $sqlConnect;
    if (!Wo_RamzaAlgorithmAdvancedAvailable() || !preg_match_all('/(?:^|\s)#([\pL\pN_]{2,80})/u', (string)$text, $matches)) {
        return;
    }
    $now = time();
    foreach (array_slice(array_unique($matches[1]), 0, 12) as $tag) {
        $name = trim(str_replace('_', ' ', $tag));
        $slug = preg_replace('/[^a-z0-9]+/', '-', Wo_RamzaAlgorithmNormalizeText($tag));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'tag-' . substr(sha1($tag), 0, 16);
        }
        $safe_slug = mysqli_real_escape_string($sqlConnect, $slug);
        $safe_name = mysqli_real_escape_string($sqlConnect, $name);
        @mysqli_query($sqlConnect, "INSERT INTO " . T_RAMZA_ALGORITHM_TOPICS . " (`parent_id`,`slug`,`name`,`topic_type`,`language`,`status`,`created_at`,`updated_at`) VALUES (0,'{$safe_slug}','{$safe_name}','hashtag','und',1,{$now},{$now}) ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`status`=1,`updated_at`=VALUES(`updated_at`)");
    }
}

function Wo_RamzaAlgorithmExtractTopicMatches($post) {
    global $sqlConnect;
    $parts = array(
        $post['postText'] ?? '', $post['postLinkTitle'] ?? '', $post['postLinkContent'] ?? '',
        $post['postFeeling'] ?? '', $post['postListening'] ?? '', $post['postTraveling'] ?? '',
        $post['postWatching'] ?? '', $post['postPlaying'] ?? '', $post['videoTitle'] ?? ''
    );
    $raw = implode(' ', $parts);
    $normalized = ' ' . Wo_RamzaAlgorithmNormalizeText($raw) . ' ';
    $matches = array();
    foreach (Wo_RamzaAlgorithmTopicDictionary() as $term => $topics) {
        $needle = ' ' . trim($term) . ' ';
        $hashtag_needle = ' #' . ltrim(trim($term), '#') . ' ';
        if (strpos($normalized, $needle) === false && strpos($normalized, $hashtag_needle) === false) {
            continue;
        }
        foreach ($topics as $topic_id => $meta) {
            $confidence = (float)$meta['confidence'];
            if (strpos($normalized, $hashtag_needle) !== false) {
                $confidence = max($confidence, 0.95);
            }
            if (!isset($matches[$topic_id]) || $matches[$topic_id]['confidence'] < $confidence) {
                $matches[$topic_id] = array('confidence' => $confidence, 'source' => $meta['source']);
            }
        }
    }
    if (preg_match_all('/(?:^|\s)#([\pL\pN_]{2,80})/u', $raw, $hashtag_matches)) {
        $slugs = array();
        foreach (array_slice(array_unique($hashtag_matches[1]), 0, 12) as $tag) {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', Wo_RamzaAlgorithmNormalizeText($tag)), '-');
            if ($slug === '') {
                $slug = 'tag-' . substr(sha1($tag), 0, 16);
            }
            $slugs[] = "'" . mysqli_real_escape_string($sqlConnect, $slug) . "'";
        }
        if (!empty($slugs)) {
            $query = @mysqli_query($sqlConnect, "SELECT `id` FROM " . T_RAMZA_ALGORITHM_TOPICS . " WHERE `slug` IN (" . implode(',', $slugs) . ") AND `status`=1");
            if ($query) {
                while ($row = mysqli_fetch_assoc($query)) {
                    $matches[(int)$row['id']] = array('confidence' => 0.98, 'source' => 'hashtag');
                }
            }
        }
    }
    uasort($matches, function ($a, $b) { return $a['confidence'] < $b['confidence'] ? 1 : -1; });
    return array_slice($matches, 0, max(1, (int)Wo_RamzaAlgorithmConfigNumber('algorithm_topic_limit_per_post', 8)), true);
}

function Wo_RamzaAlgorithmEnrichPosts($posts, $ctx = array()) {
    global $sqlConnect;
    if (empty($posts) || !Wo_RamzaAlgorithmAdvancedAvailable()) {
        return $posts;
    }
    $ids = array();
    foreach ($posts as $post) {
        if (!empty($post['id']) && is_numeric($post['id'])) {
            $ids[] = (int)$post['id'];
        }
    }
    $ids = array_values(array_unique($ids));
    if (empty($ids)) {
        return $posts;
    }
    $topic_map = array();
    $safe_ids = implode(',', $ids);
    $query = @mysqli_query($sqlConnect, "SELECT `post_id`,`topic_id`,`confidence` FROM " . T_RAMZA_ALGORITHM_POST_TOPICS . " WHERE `post_id` IN ({$safe_ids}) AND `topic_id` > 0");
    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $topic_map[(int)$row['post_id']][(int)$row['topic_id']] = (float)$row['confidence'];
        }
    }
    $all_topic_ids = array();
    foreach ($posts as $key => $post) {
        $post_id = (int)($post['id'] ?? 0);
        $posts[$key]['_ramza_algorithm_topics'] = $topic_map[$post_id] ?? array();
        if (empty($posts[$key]['_ramza_algorithm_topics'])) {
            foreach (Wo_RamzaAlgorithmExtractTopicMatches($post) as $topic_id => $meta) {
                $posts[$key]['_ramza_algorithm_topics'][(int)$topic_id] = (float)$meta['confidence'];
            }
        }
        foreach (array_keys($posts[$key]['_ramza_algorithm_topics']) as $topic_id) {
            if ((int)$topic_id > 0) {
                $all_topic_ids[(int)$topic_id] = true;
            }
        }
        $posts[$key]['_ramza_algorithm_boost'] = 0;
    }
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_BOOSTS)) {
        $now = time();
        $cap = max(0, min(25, Wo_RamzaAlgorithmConfigNumber('algorithm_rank_boost_cap', 8)));
        $target_where = "(`post_id` IN ({$safe_ids}) AND `post_id`>0)";
        if (!empty($all_topic_ids)) {
            $target_where .= " OR (`topic_id` IN (" . implode(',', array_keys($all_topic_ids)) . ") AND `topic_id`>0)";
        }
        $query = @mysqli_query($sqlConnect, "SELECT `post_id`,`topic_id`,LEAST(`weight`,{$cap}) AS `boost_weight` FROM " . T_RAMZA_ALGORITHM_BOOSTS . " WHERE `active`=1 AND (`starts_at`=0 OR `starts_at`<={$now}) AND (`ends_at`=0 OR `ends_at`>={$now}) AND ({$target_where})");
        if ($query) {
            while ($row = mysqli_fetch_assoc($query)) {
                $boost_post_id = (int)$row['post_id'];
                $boost_topic_id = (int)$row['topic_id'];
                $weight = max(0, (float)$row['boost_weight']);
                foreach ($posts as $key => $post) {
                    if (($boost_post_id > 0 && (int)($post['id'] ?? 0) === $boost_post_id) || ($boost_topic_id > 0 && isset($post['_ramza_algorithm_topics'][$boost_topic_id]))) {
                        $posts[$key]['_ramza_algorithm_boost'] = min($cap, (float)$posts[$key]['_ramza_algorithm_boost'] + $weight);
                    }
                }
            }
        }
    }
    return $posts;
}

function Wo_RamzaAlgorithmBuildAdvancedContext($user_id, $ctx = array()) {
    global $sqlConnect, $wo;
    $ctx['advanced_available'] = false;
    $ctx['topic_affinity'] = array();
    $ctx['related_topic_affinity'] = array();
    $ctx['collaborative_topic_affinity'] = array();
    $ctx['seen_posts'] = array();
    $ctx['language'] = (string)($wo['user']['language'] ?? '');
    if (!Wo_RamzaAlgorithmAdvancedAvailable()) {
        return $ctx;
    }
    $ctx['advanced_available'] = true;
    $user_id = (int)$user_id;
    $now = time();
    $half_life = max(1, Wo_RamzaAlgorithmConfigNumber('algorithm_interest_decay_days', 21)) * 86400;
    $query = @mysqli_query($sqlConnect, "SELECT `topic_id`,`score`,`last_event_at` FROM " . T_RAMZA_ALGORITHM_USER_INTERESTS . " WHERE `user_id`={$user_id} AND `score`<>0 ORDER BY ABS(`score`) DESC LIMIT 80");
    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $decay = pow(0.5, max(0, $now - (int)$row['last_event_at']) / $half_life);
            $ctx['topic_affinity'][(int)$row['topic_id']] = (float)$row['score'] * $decay;
        }
    }
    $positive_topics = array();
    foreach ($ctx['topic_affinity'] as $topic_id => $score) {
        if ($score > 0) {
            $positive_topics[$topic_id] = $score;
        }
    }
    arsort($positive_topics);
    $positive_topics = array_slice($positive_topics, 0, max(3, (int)Wo_RamzaAlgorithmConfigNumber('algorithm_candidate_topic_limit', 12)), true);
    if (!empty($positive_topics) && Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_TOPIC_RELATIONS)) {
        $topic_ids = implode(',', array_map('intval', array_keys($positive_topics)));
        $query = @mysqli_query($sqlConnect, "SELECT `topic_id`,`related_topic_id`,`weight` FROM " . T_RAMZA_ALGORITHM_TOPIC_RELATIONS . " WHERE `topic_id` IN ({$topic_ids}) ORDER BY `weight` DESC LIMIT 120");
        if ($query) {
            while ($row = mysqli_fetch_assoc($query)) {
                $source = (int)$row['topic_id'];
                $target = (int)$row['related_topic_id'];
                $value = ($positive_topics[$source] ?? 0) * max(0, min(1, (float)$row['weight']));
                $ctx['related_topic_affinity'][$target] = max($ctx['related_topic_affinity'][$target] ?? 0, $value);
            }
        }
        $peer_query = @mysqli_query($sqlConnect, "SELECT `user_id`,SUM(`score`) AS overlap_score FROM " . T_RAMZA_ALGORITHM_USER_INTERESTS . " WHERE `user_id`<>{$user_id} AND `topic_id` IN ({$topic_ids}) AND `score`>0 GROUP BY `user_id` ORDER BY overlap_score DESC LIMIT 24");
        $peer_ids = array();
        if ($peer_query) {
            while ($row = mysqli_fetch_assoc($peer_query)) {
                $peer_ids[] = (int)$row['user_id'];
            }
        }
        if (!empty($peer_ids)) {
            $peer_list = implode(',', $peer_ids);
            $query = @mysqli_query($sqlConnect, "SELECT `topic_id`,AVG(`score`) AS peer_score FROM " . T_RAMZA_ALGORITHM_USER_INTERESTS . " WHERE `user_id` IN ({$peer_list}) AND `score`>0 GROUP BY `topic_id` ORDER BY peer_score DESC LIMIT 40");
            if ($query) {
                while ($row = mysqli_fetch_assoc($query)) {
                    $ctx['collaborative_topic_affinity'][(int)$row['topic_id']] = (float)$row['peer_score'];
                }
            }
        }
    }
    if (Wo_RamzaAlgorithmConfigSwitch('algorithm_track_impressions', '1') && Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_IMPRESSIONS)) {
        $cutoff = $now - (max(1, Wo_RamzaAlgorithmConfigNumber('algorithm_candidate_seen_hours', 48)) * 3600);
        $query = @mysqli_query($sqlConnect, "SELECT `post_id`,MAX(`last_seen_at`) AS last_seen_at,SUM(`impression_count`) AS impressions FROM " . T_RAMZA_ALGORITHM_IMPRESSIONS . " WHERE `user_id`={$user_id} AND `last_seen_at`>={$cutoff} GROUP BY `post_id` ORDER BY last_seen_at DESC LIMIT 800");
        if ($query) {
            while ($row = mysqli_fetch_assoc($query)) {
                $ctx['seen_posts'][(int)$row['post_id']] = array('time' => (int)$row['last_seen_at'], 'count' => (int)$row['impressions']);
            }
        }
    }
    return $ctx;
}

function Wo_RamzaAlgorithmNormalizeAffinity($score) {
    $score = (float)$score;
    return $score >= 0 ? 1 - exp(-$score / 4) : -(1 - exp(-abs($score) / 4));
}

function Wo_RamzaAlgorithmAdvancedScorePost($post, $ctx) {
    $topics = $post['_ramza_algorithm_topics'] ?? array();
    $topic_match = 0;
    $related_match = 0;
    $collaborative_match = 0;
    $negative_match = 0;
    $best_topic_id = 0;
    foreach ($topics as $topic_id => $confidence) {
        $confidence = max(0, min(1, (float)$confidence));
        $direct = Wo_RamzaAlgorithmNormalizeAffinity($ctx['topic_affinity'][$topic_id] ?? 0) * $confidence;
        if ($direct >= 0) {
            if ($direct > $topic_match) {
                $topic_match = $direct;
                $best_topic_id = (int)$topic_id;
            }
        } else {
            $negative_match = max($negative_match, abs($direct));
        }
        $related_match = max($related_match, Wo_RamzaAlgorithmNormalizeAffinity($ctx['related_topic_affinity'][$topic_id] ?? 0) * $confidence);
        $collaborative_match = max($collaborative_match, Wo_RamzaAlgorithmNormalizeAffinity($ctx['collaborative_topic_affinity'][$topic_id] ?? 0) * $confidence);
    }
    $post_user_id = (int)($post['user_id'] ?? 0);
    $post_page_id = (int)($post['page_id'] ?? 0);
    $post_group_id = (int)($post['group_id'] ?? 0);
    $network = !empty($ctx['following'][$post_user_id]) ? 1 : 0;
    if ($post_page_id && !empty($ctx['liked_pages'][$post_page_id])) {
        $network = max($network, 0.9);
    }
    if ($post_group_id && !empty($ctx['joined_groups'][$post_group_id])) {
        $network = max($network, 0.85);
    }
    $creator_affinity = Wo_RamzaAlgorithmNormalizeAffinity($ctx['author_affinity'][$post_user_id] ?? 0);
    $age_hours = !empty($post['time']) ? max(0, (time() - (int)$post['time']) / 3600) : 9999;
    $views_count = max((int)($post['views'] ?? 0), (int)($post['videoViews'] ?? 0));
    $engagement = (int)($post['post_likes'] ?? 0) + (int)($post['post_wonders'] ?? 0) + ((int)($post['post_comments'] ?? 0) * 1.5) + ((int)($post['post_shares'] ?? 0) * 2) + ($views_count * 0.05);
    $quality = min(1, log(1 + ($engagement / max(1, sqrt($age_hours + 1)))) / log(35));
    $seen_penalty = 0;
    $post_id = (int)($post['id'] ?? 0);
    if (!empty($ctx['seen_posts'][$post_id])) {
        $seen = $ctx['seen_posts'][$post_id];
        $seen_age = max(0, time() - (int)$seen['time']);
        $seen_penalty = min(1, (0.45 + (min(4, (int)$seen['count']) * 0.12)) * exp(-$seen_age / 86400));
    }
    $mode = Wo_RamzaAlgorithmConfigValue('algorithm_relevance_mode', 'balanced');
    $mode_topic = 1;
    $mode_related = 1;
    $unmatched_penalty = 0;
    if ($mode === 'broad') {
        $mode_topic = 0.75;
        $mode_related = 1.25;
    } else if ($mode === 'strict') {
        $mode_topic = 1.30;
        $mode_related = 0.75;
        $unmatched_penalty = !empty($ctx['topic_affinity']) && empty($topics) ? 8 : 0;
    } else if ($mode === 'very_strict') {
        $mode_topic = 1.65;
        $mode_related = 0.55;
        $unmatched_penalty = !empty($ctx['topic_affinity']) && ($topic_match + $related_match) < 0.08 ? 16 : 0;
    }
    $delta = ($topic_match * Wo_RamzaAlgorithmConfigNumber('algorithm_rank_topic_match', 34) * $mode_topic)
        + ($related_match * Wo_RamzaAlgorithmConfigNumber('algorithm_rank_related_topic', 18) * $mode_related)
        + ($collaborative_match * Wo_RamzaAlgorithmConfigNumber('algorithm_rank_collaborative', 10))
        + ($creator_affinity * Wo_RamzaAlgorithmConfigNumber('algorithm_rank_creator_affinity', 20))
        + ($quality * Wo_RamzaAlgorithmConfigNumber('algorithm_rank_quality', 12))
        + ($network * Wo_RamzaAlgorithmConfigNumber('algorithm_rank_network', 18))
        + min(Wo_RamzaAlgorithmConfigNumber('algorithm_rank_boost_cap', 8), (float)($post['_ramza_algorithm_boost'] ?? 0))
        - ($negative_match * Wo_RamzaAlgorithmConfigNumber('algorithm_rank_negative_topic', 42))
        - ($seen_penalty * Wo_RamzaAlgorithmConfigNumber('algorithm_rank_seen_penalty', 35))
        - $unmatched_penalty;
    $pool = 'exploration';
    if ($network >= 0.8) {
        $pool = 'followed';
    } else if ($topic_match >= 0.10) {
        $pool = 'interest';
    } else if ($related_match >= 0.10) {
        $pool = 'related';
    } else if ($collaborative_match >= 0.10) {
        $pool = 'collaborative';
    } else if ($quality >= 0.50 && $age_hours <= Wo_RamzaAlgorithmConfigNumber('algorithm_trending_window_hours', 24)) {
        $pool = 'trending';
    } else if (empty($ctx['author_affinity'][$post_user_id])) {
        $pool = 'new_creator';
    }
    $reason = $pool === 'followed' ? 'From your network' : ($pool === 'interest' ? 'Matches your interests' : ($pool === 'related' ? 'Related to your interests' : ($pool === 'collaborative' ? 'Popular with people sharing your interests' : ($pool === 'trending' ? 'Trending now' : ($pool === 'new_creator' ? 'New creator discovery' : 'Discovery')))));
    return array(
        'delta' => $delta, 'pool' => $pool, 'reason' => $reason,
        'topic_match' => $topic_match, 'related_match' => $related_match,
        'collaborative_match' => $collaborative_match, 'negative_match' => $negative_match,
        'quality' => $quality, 'seen_penalty' => $seen_penalty, 'best_topic_id' => $best_topic_id
    );
}

function Wo_RamzaAlgorithmComposeFeed($ranked, $return_limit) {
    if (count($ranked) < 3) {
        return $ranked;
    }
    $return_limit = max(1, min(count($ranked), (int)$return_limit));
    $weights = array(
        'followed' => max(0, Wo_RamzaAlgorithmConfigNumber('algorithm_pool_followed', 40)),
        'interest' => max(0, Wo_RamzaAlgorithmConfigNumber('algorithm_pool_interest', 30)),
        'related' => max(0, Wo_RamzaAlgorithmConfigNumber('algorithm_pool_related', 10)),
        'collaborative' => max(0, Wo_RamzaAlgorithmConfigNumber('algorithm_pool_collaborative', 5)),
        'trending' => max(0, Wo_RamzaAlgorithmConfigNumber('algorithm_pool_trending', 5)),
        'new_creator' => max(0, Wo_RamzaAlgorithmConfigNumber('algorithm_pool_new_creator', 5)),
        'exploration' => max(0, Wo_RamzaAlgorithmConfigNumber('algorithm_pool_exploration', 5))
    );
    $relevance_mode = Wo_RamzaAlgorithmConfigValue('algorithm_relevance_mode', 'balanced');
    if ($relevance_mode === 'very_strict') {
        $weights['trending'] = 0;
        $weights['new_creator'] = 0;
        $weights['exploration'] = 0;
    } else if ($relevance_mode === 'strict') {
        $weights['exploration'] = min(2, $weights['exploration']);
    }
    $sum = array_sum($weights);
    if ($sum <= 0) {
        return $ranked;
    }
    $queues = array_fill_keys(array_keys($weights), array());
    foreach ($ranked as $post) {
        $pool = $post['_ramza_algorithm_pool'] ?? 'exploration';
        if (!isset($queues[$pool])) {
            $pool = 'exploration';
        }
        $queues[$pool][] = $post;
    }
    // Keep one controlled discovery slot in normal feed pages.  Without this
    // reservation, the minimum-one quotas for the earlier pools can consume
    // every visible position before exploration/new-creator candidates are
    // considered.  The reservation is deliberately disabled for tiny pages.
    $discovery_post = null;
    if ($return_limit >= 4 && $relevance_mode !== 'very_strict') {
        if ($weights['exploration'] > 0 && !empty($queues['exploration'])) {
            $discovery_post = array_shift($queues['exploration']);
        } else if ($weights['new_creator'] > 0 && !empty($queues['new_creator'])) {
            $discovery_post = array_shift($queues['new_creator']);
        }
    }
    $quota_capacity = $return_limit - ($discovery_post !== null ? 1 : 0);
    $head = array();
    $selected = array();
    foreach ($weights as $pool => $weight) {
        $target = (int)floor($return_limit * ($weight / $sum));
        if ($weight > 0 && $target === 0 && !empty($queues[$pool]) && count($head) < $quota_capacity) {
            $target = 1;
        }
        for ($i = 0; $i < $target && !empty($queues[$pool]) && count($head) < $quota_capacity; $i++) {
            $post = array_shift($queues[$pool]);
            $head[] = $post;
            $selected[(int)$post['id']] = true;
        }
    }
    if ($discovery_post !== null) {
        $head[] = $discovery_post;
        $selected[(int)$discovery_post['id']] = true;
    }
    foreach ($ranked as $post) {
        $id = (int)($post['id'] ?? 0);
        if (count($head) < $return_limit && empty($selected[$id])) {
            $head[] = $post;
            $selected[$id] = true;
        }
    }
    $tail = array();
    foreach ($ranked as $post) {
        if (empty($selected[(int)($post['id'] ?? 0)])) {
            $tail[] = $post;
        }
    }
    return array_merge($head, $tail);
}

function Wo_RamzaAlgorithmEventWeights() {
    return array(
        'impression' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_impression_event', 0.10),
        'click' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_click_event', 1.00),
        'open_post' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_open_post_event', 1.50),
        'like' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_like_event', 3.00),
        'reaction' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_reaction_event', 3.00),
        'comment' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_comment_event', 5.00),
        'reply' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_reply_event', 5.00),
        'share' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_share_event', 7.00),
        'repost' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_repost_event', 8.00),
        'save' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_save_event', 8.00),
        'follow_after_view' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_follow_after_view_event', 10.00),
        'profile_visit' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_profile_visit_event', 3.00),
        'watch_25' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_watch_25_event', 1.00),
        'watch_50' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_watch_50_event', 3.00),
        'watch_75' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_watch_75_event', 5.00),
        'watch_complete' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_watch_complete_event', 8.00),
        'rewatch' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_rewatch_event', 10.00),
        'sound_on' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_sound_on_event', 1.50),
        'dwell' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_dwell_event', 0.80),
        'expand_text' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_expand_text_event', 1.50),
        'open_comments' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_open_comments_event', 1.50),
        'search_topic' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_search_topic_event', 6.00),
        'hashtag_click' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_hashtag_click_event', 4.00),
        'send_post' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_send_post_event', 7.00),
        'topic_preference' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_topic_preference_event', 8.00),
        'quick_skip' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_quick_skip_event', -2.00),
        'short_watch' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_short_watch_event', -2.00),
        'hide' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_hide_event', -8.00),
        'not_interested' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_not_interested_event', -12.00),
        'unfollow' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_unfollow_event', -10.00),
        'mute' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_mute_event', -15.00),
        'report' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_report_event', -25.00),
        'block' => Wo_RamzaAlgorithmConfigNumber('algorithm_signal_block_event', -100.00)
    );
}

function Wo_RamzaAlgorithmValidateAdminSetting($key, $value) {
    $switches = array(
        'algorithm_system','algorithm_track_behavior','algorithm_track_search','algorithm_track_video_watch',
        'algorithm_track_likes','algorithm_track_comments','algorithm_track_shares','algorithm_track_follows',
        'algorithm_track_categories','algorithm_track_location','algorithm_sensitive_filter','algorithm_advanced_topics',
        'algorithm_track_impressions','algorithm_track_dwell','algorithm_track_quick_skips','algorithm_debug_mode'
    );
    if (in_array($key, $switches, true)) {
        return ((string)$value === '1') ? '1' : '0';
    }
    if ($key === 'algorithm_profile_mode') {
        return in_array($value, array('balanced','following_first','interest_first','trending_first','fresh_first'), true) ? $value : 'balanced';
    }
    if ($key === 'algorithm_relevance_mode') {
        return in_array($value, array('broad','balanced','strict','very_strict'), true) ? $value : 'balanced';
    }
    if ($key === 'algorithm_admin_stage') {
        return 'advanced_recommendation_connected';
    }
    $ranges = array(
        'algorithm_signal_following' => array(0,100), 'algorithm_signal_interest' => array(0,100),
        'algorithm_signal_trending' => array(0,100), 'algorithm_signal_freshness' => array(0,100),
        'algorithm_signal_exploration' => array(0,100), 'algorithm_signal_location' => array(0,100),
        'algorithm_multiplier_search' => array(0,5), 'algorithm_multiplier_video' => array(0,5),
        'algorithm_multiplier_like_category' => array(0,5), 'algorithm_multiplier_trending' => array(0,5),
        'algorithm_trending_window_hours' => array(1,168), 'algorithm_freshness_hours' => array(1,720),
        'algorithm_max_same_author' => array(1,20), 'algorithm_max_same_category' => array(1,30),
        'algorithm_max_author_streak' => array(1,5), 'algorithm_max_category_streak' => array(1,6),
        'algorithm_max_topic_streak' => array(1,6),
        'algorithm_diversity_window' => array(2,20), 'algorithm_min_exploration_percent' => array(0,60),
        'algorithm_affinity_decay' => array(0.90,1), 'algorithm_negative_feedback_penalty' => array(0,1),
        'algorithm_signal_impression_event' => array(-100,25), 'algorithm_signal_click_event' => array(-100,25),
        'algorithm_signal_open_post_event' => array(-100,25), 'algorithm_signal_like_event' => array(-100,25),
        'algorithm_signal_reaction_event' => array(-100,25), 'algorithm_signal_comment_event' => array(-100,25),
        'algorithm_signal_reply_event' => array(-100,25), 'algorithm_signal_share_event' => array(-100,25),
        'algorithm_signal_repost_event' => array(-100,25), 'algorithm_signal_save_event' => array(-100,25),
        'algorithm_signal_follow_after_view_event' => array(-100,25), 'algorithm_signal_profile_visit_event' => array(-100,25),
        'algorithm_signal_watch_25_event' => array(-100,25), 'algorithm_signal_watch_50_event' => array(-100,25),
        'algorithm_signal_watch_75_event' => array(-100,25), 'algorithm_signal_watch_complete_event' => array(-100,25),
        'algorithm_signal_rewatch_event' => array(-100,25), 'algorithm_signal_sound_on_event' => array(-100,25),
        'algorithm_signal_dwell_event' => array(-100,25), 'algorithm_signal_expand_text_event' => array(-100,25),
        'algorithm_signal_open_comments_event' => array(-100,25), 'algorithm_signal_search_topic_event' => array(-100,25),
        'algorithm_signal_hashtag_click_event' => array(-100,25), 'algorithm_signal_send_post_event' => array(-100,25),
        'algorithm_signal_topic_preference_event' => array(-100,25), 'algorithm_signal_quick_skip_event' => array(-100,25),
        'algorithm_signal_short_watch_event' => array(-100,25), 'algorithm_signal_hide_event' => array(-100,25),
        'algorithm_signal_not_interested_event' => array(-100,25), 'algorithm_signal_unfollow_event' => array(-100,25),
        'algorithm_signal_mute_event' => array(-100,25), 'algorithm_signal_report_event' => array(-100,25),
        'algorithm_signal_block_event' => array(-100,25), 'algorithm_rank_topic_match' => array(0,100),
        'algorithm_rank_related_topic' => array(0,100), 'algorithm_rank_collaborative' => array(0,100),
        'algorithm_rank_negative_topic' => array(0,100),
        'algorithm_rank_creator_affinity' => array(0,100), 'algorithm_rank_quality' => array(0,100),
        'algorithm_rank_network' => array(0,100), 'algorithm_rank_seen_penalty' => array(0,100),
        'algorithm_rank_boost_cap' => array(0,25), 'algorithm_pool_followed' => array(0,100),
        'algorithm_pool_interest' => array(0,100), 'algorithm_pool_related' => array(0,100),
        'algorithm_pool_collaborative' => array(0,100), 'algorithm_pool_trending' => array(0,100),
        'algorithm_pool_new_creator' => array(0,100), 'algorithm_pool_exploration' => array(0,100),
        'algorithm_interest_decay_days' => array(1,365), 'algorithm_event_retention_days' => array(7,365),
        'algorithm_impression_retention_days' => array(2,90), 'algorithm_event_batch_limit' => array(1,100),
        'algorithm_topic_limit_per_post' => array(1,20), 'algorithm_candidate_topic_limit' => array(3,40),
        'algorithm_candidate_seen_hours' => array(1,720)
    );
    if (!isset($ranges[$key]) || !is_numeric($value)) {
        return null;
    }
    $number = max($ranges[$key][0], min($ranges[$key][1], (float)$value));
    return (string)$number;
}

function Wo_RamzaAlgorithmRecordEvents($user_id, $events, $session_key = '') {
    global $sqlConnect;
    if (!Wo_RamzaAlgorithmConfigSwitch('algorithm_system', '0') || !Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_EVENTS)) {
        return array('accepted' => 0, 'ignored' => count((array)$events), 'message' => 'Algorithm event storage is not available.');
    }
    $user_id = (int)$user_id;
    $weights = Wo_RamzaAlgorithmEventWeights();
    $limit = max(1, min(100, (int)Wo_RamzaAlgorithmConfigNumber('algorithm_event_batch_limit', 40)));
    $events = array_slice(is_array($events) ? $events : array(), 0, $limit);
    $session_key = preg_match('/^[a-f0-9]{40}$/', (string)$session_key) ? $session_key : sha1(session_id() . ':' . $user_id);
    $accepted = 0;
    $ignored = 0;
    $needs_realtime_aggregation = false;
    $now = time();
    foreach ($events as $event) {
        if (!is_array($event)) {
            $ignored++;
            continue;
        }
        $type = strtolower(trim((string)($event['type'] ?? '')));
        $post_id = isset($event['post_id']) && is_numeric($event['post_id']) ? (int)$event['post_id'] : 0;
        $topic_id = isset($event['topic_id']) && is_numeric($event['topic_id']) ? (int)$event['topic_id'] : 0;
        $topic_only_types = array('search_topic', 'topic_preference');
        if (!isset($weights[$type]) || ($post_id < 1 && ($topic_id < 1 || !in_array($type, $topic_only_types, true)))) {
            $ignored++;
            continue;
        }
        if ($post_id > 0) {
            // Never trust a client-supplied topic for a post. The processor uses
            // server-classified post topics, preventing arbitrary score writes.
            $topic_id = 0;
            $post = Wo_PostData($post_id);
            if (!is_array($post) || empty($post['id'])) {
                $ignored++;
                continue;
            }
            $hidden = @mysqli_query($sqlConnect, "SELECT `id` FROM " . T_HIDDEN_POSTS . " WHERE `user_id`={$user_id} AND `post_id`={$post_id} LIMIT 1");
            if ($hidden && mysqli_num_rows($hidden) > 0 && !in_array($type, array('hide','not_interested','mute','report','block'), true)) {
                $ignored++;
                continue;
            }
        } else {
            $topic_query = @mysqli_query($sqlConnect, "SELECT `id` FROM " . T_RAMZA_ALGORITHM_TOPICS . " WHERE `id`={$topic_id} AND `status`=1 LIMIT 1");
            if (!$topic_query || mysqli_num_rows($topic_query) < 1) {
                $ignored++;
                continue;
            }
        }
        $duration = isset($event['duration_ms']) && is_numeric($event['duration_ms']) ? max(0, min(1800000, (int)$event['duration_ms'])) : 0;
        $strength = (float)$weights[$type];
        if ($type === 'dwell') {
            $strength *= min(1, $duration / 30000);
        } else if ($type === 'quick_skip' && $duration > 5000) {
            $ignored++;
            continue;
        }
        $surface = strtolower(trim((string)($event['surface'] ?? 'home')));
        if (!in_array($surface, array('home','reels','watch','post','search'), true)) {
            $surface = 'home';
        }
        $client_id = preg_replace('/[^a-zA-Z0-9_.:-]/', '', (string)($event['event_id'] ?? ''));
        $event_key = sha1($user_id . ':' . $session_key . ':' . $post_id . ':' . $topic_id . ':' . $type . ':' . ($client_id !== '' ? $client_id : floor($now / 5)));
        $safe_type = mysqli_real_escape_string($sqlConnect, $type);
        $safe_surface = mysqli_real_escape_string($sqlConnect, $surface);
        $safe_session = mysqli_real_escape_string($sqlConnect, $session_key);
        $safe_key = mysqli_real_escape_string($sqlConnect, $event_key);
        $metadata = array(
            'position' => isset($event['position']) && is_numeric($event['position']) ? max(0, min(1000, (int)$event['position'])) : 0,
            'watch_percentage' => isset($event['watch_percentage']) && is_numeric($event['watch_percentage']) ? max(0, min(100, (float)$event['watch_percentage'])) : 0,
            'source' => substr(preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($event['source'] ?? ''))), 0, 32),
            'reason' => substr(trim(strip_tags((string)($event['reason'] ?? ''))), 0, 160),
            'device' => in_array(($event['device'] ?? ''), array('desktop','mobile','tablet'), true) ? $event['device'] : 'unknown'
        );
        $safe_metadata = mysqli_real_escape_string($sqlConnect, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $strength_sql = number_format(max(-100, min(25, $strength)), 4, '.', '');
        $insert = @mysqli_query($sqlConnect, "INSERT IGNORE INTO " . T_RAMZA_ALGORITHM_EVENTS . " (`user_id`,`post_id`,`topic_id`,`event_type`,`strength`,`duration_ms`,`surface`,`session_key`,`event_key`,`metadata_json`,`created_at`) VALUES ({$user_id},{$post_id},{$topic_id},'{$safe_type}',{$strength_sql},{$duration},'{$safe_surface}','{$safe_session}','{$safe_key}','{$safe_metadata}',{$now})");
        if ($insert && mysqli_affected_rows($sqlConnect) > 0) {
            $accepted++;
            if (in_array($type, array('like','reaction','comment','reply','share','repost','send_post','save','follow_after_view','profile_visit','expand_text','open_comments','hashtag_click','topic_preference','hide','not_interested','unfollow','mute','report','block'), true) || ($type === 'dwell' && $duration >= 3000)) {
                $needs_realtime_aggregation = true;
            }
        } else {
            $ignored++;
        }
        if ($post_id > 0 && $type === 'impression' && Wo_RamzaAlgorithmConfigSwitch('algorithm_track_impressions', '1') && Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_IMPRESSIONS)) {
            @mysqli_query($sqlConnect, "INSERT INTO " . T_RAMZA_ALGORITHM_IMPRESSIONS . " (`user_id`,`post_id`,`surface`,`session_key`,`impression_count`,`dwell_ms`,`first_seen_at`,`last_seen_at`) VALUES ({$user_id},{$post_id},'{$safe_surface}','{$safe_session}',1,{$duration},{$now},{$now}) ON DUPLICATE KEY UPDATE `impression_count`=LEAST(65535,`impression_count`+1),`dwell_ms`=GREATEST(`dwell_ms`,VALUES(`dwell_ms`)),`last_seen_at`=VALUES(`last_seen_at`)");
        } else if ($post_id > 0 && $type === 'dwell' && Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_IMPRESSIONS)) {
            @mysqli_query($sqlConnect, "UPDATE " . T_RAMZA_ALGORITHM_IMPRESSIONS . " SET `dwell_ms`=GREATEST(`dwell_ms`,{$duration}),`last_seen_at`={$now} WHERE `user_id`={$user_id} AND `post_id`={$post_id} AND `surface`='{$safe_surface}' AND `session_key`='{$safe_session}'");
        }
    }
    // High-intent actions should affect the next feed request, not wait for a
    // cron cycle. Low-value impressions remain batch processed for scale.
    if ($accepted > 0 && $needs_realtime_aggregation && Wo_RamzaAlgorithmAdvancedAvailable()) {
        Wo_RamzaAlgorithmIndexRecentPosts(30);
        Wo_RamzaAlgorithmAggregateEvents(min(150, max(40, $accepted * 10)));
    }
    return array('accepted' => $accepted, 'ignored' => $ignored, 'message' => 'Behavior batch recorded.');
}

function Wo_RamzaAlgorithmIndexRecentPosts($limit = 120) {
    global $sqlConnect;
    if (!Wo_RamzaAlgorithmAdvancedAvailable()) {
        return 0;
    }
    $limit = max(1, min(500, (int)$limit));
    $sql = "SELECT p.`id`,p.`postText`,p.`postLinkTitle`,p.`postLinkContent`,p.`postFeeling`,p.`postListening`,p.`postTraveling`,p.`postWatching`,p.`postPlaying`,p.`videoTitle` FROM " . T_POSTS . " p LEFT JOIN " . T_RAMZA_ALGORITHM_POST_TOPICS . " pt ON pt.`post_id`=p.`id` WHERE p.`active`=1 AND pt.`post_id` IS NULL ORDER BY p.`id` DESC LIMIT {$limit}";
    $query = @mysqli_query($sqlConnect, $sql);
    if (!$query) {
        return 0;
    }
    $processed = 0;
    $now = time();
    while ($post = mysqli_fetch_assoc($query)) {
        $post_id = (int)$post['id'];
        Wo_RamzaAlgorithmEnsureHashtagTopics($post['postText'] ?? '');
        $matches = Wo_RamzaAlgorithmExtractTopicMatches($post);
        if (empty($matches)) {
            @mysqli_query($sqlConnect, "INSERT IGNORE INTO " . T_RAMZA_ALGORITHM_POST_TOPICS . " (`post_id`,`topic_id`,`source`,`confidence`,`created_at`,`updated_at`) VALUES ({$post_id},0,'none',0,{$now},{$now})");
        } else {
            foreach ($matches as $topic_id => $meta) {
                $topic_id = (int)$topic_id;
                $confidence = number_format(max(0, min(1, (float)$meta['confidence'])), 4, '.', '');
                $source = mysqli_real_escape_string($sqlConnect, substr((string)$meta['source'], 0, 24));
                @mysqli_query($sqlConnect, "INSERT INTO " . T_RAMZA_ALGORITHM_POST_TOPICS . " (`post_id`,`topic_id`,`source`,`confidence`,`created_at`,`updated_at`) VALUES ({$post_id},{$topic_id},'{$source}',{$confidence},{$now},{$now}) ON DUPLICATE KEY UPDATE `source`=VALUES(`source`),`confidence`=GREATEST(`confidence`,VALUES(`confidence`)),`updated_at`=VALUES(`updated_at`)");
            }
        }
        $processed++;
    }
    if ($processed > 0) {
        @mysqli_query($sqlConnect, "UPDATE " . T_RAMZA_ALGORITHM_TOPICS . " t SET t.`post_count`=(SELECT COUNT(*) FROM " . T_RAMZA_ALGORITHM_POST_TOPICS . " pt WHERE pt.`topic_id`=t.`id`)");
    }
    return $processed;
}

function Wo_RamzaAlgorithmAggregateEvents($limit = 500) {
    global $sqlConnect;
    if (!Wo_RamzaAlgorithmAdvancedAvailable() || !Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_EVENTS)) {
        return 0;
    }
    $limit = max(1, min(2000, (int)$limit));
    $query = @mysqli_query($sqlConnect, "SELECT `id`,`user_id`,`post_id`,`topic_id`,`strength`,`created_at` FROM " . T_RAMZA_ALGORITHM_EVENTS . " WHERE `processed_at`=0 ORDER BY `id` ASC LIMIT {$limit}");
    if (!$query) {
        return 0;
    }
    $events = array();
    $post_ids = array();
    while ($row = mysqli_fetch_assoc($query)) {
        $events[] = $row;
        if (empty($row['topic_id']) && !empty($row['post_id'])) {
            $post_ids[] = (int)$row['post_id'];
        }
    }
    if (empty($events)) {
        return 0;
    }
    $post_topics = array();
    if (!empty($post_ids)) {
        $list = implode(',', array_unique($post_ids));
        $topic_query = @mysqli_query($sqlConnect, "SELECT `post_id`,`topic_id`,`confidence` FROM " . T_RAMZA_ALGORITHM_POST_TOPICS . " WHERE `post_id` IN ({$list}) AND `topic_id`>0");
        if ($topic_query) {
            while ($row = mysqli_fetch_assoc($topic_query)) {
                $post_topics[(int)$row['post_id']][(int)$row['topic_id']] = (float)$row['confidence'];
            }
        }
    }
    $aggregate = array();
    $event_ids = array();
    foreach ($events as $event) {
        $event_ids[] = (int)$event['id'];
        $topics = !empty($event['topic_id']) ? array((int)$event['topic_id'] => 1) : ($post_topics[(int)$event['post_id']] ?? array());
        foreach ($topics as $topic_id => $confidence) {
            $key = (int)$event['user_id'] . ':' . (int)$topic_id;
            if (!isset($aggregate[$key])) {
                $aggregate[$key] = array('user_id' => (int)$event['user_id'], 'topic_id' => (int)$topic_id, 'delta' => 0, 'positive' => 0, 'negative' => 0, 'count' => 0, 'time' => 0);
            }
            $delta = (float)$event['strength'] * max(0, min(1, (float)$confidence));
            $aggregate[$key]['delta'] += $delta;
            $aggregate[$key][$delta >= 0 ? 'positive' : 'negative'] += abs($delta);
            $aggregate[$key]['count']++;
            $aggregate[$key]['time'] = max($aggregate[$key]['time'], (int)$event['created_at']);
        }
    }
    $now = time();
    foreach ($aggregate as $item) {
        $delta = number_format(max(-100, min(100, $item['delta'])), 5, '.', '');
        $positive = number_format(min(100, $item['positive']), 5, '.', '');
        $negative = number_format(min(100, $item['negative']), 5, '.', '');
        @mysqli_query($sqlConnect, "INSERT INTO " . T_RAMZA_ALGORITHM_USER_INTERESTS . " (`user_id`,`topic_id`,`score`,`positive_score`,`negative_score`,`event_count`,`last_event_at`,`updated_at`) VALUES ({$item['user_id']},{$item['topic_id']},{$delta},{$positive},{$negative},{$item['count']},{$item['time']},{$now}) ON DUPLICATE KEY UPDATE `score`=GREATEST(-100,LEAST(100,`score`+VALUES(`score`))),`positive_score`=LEAST(1000,`positive_score`+VALUES(`positive_score`)),`negative_score`=LEAST(1000,`negative_score`+VALUES(`negative_score`)),`event_count`=`event_count`+VALUES(`event_count`),`last_event_at`=GREATEST(`last_event_at`,VALUES(`last_event_at`)),`updated_at`=VALUES(`updated_at`)");
    }
    $id_list = implode(',', $event_ids);
    @mysqli_query($sqlConnect, "UPDATE " . T_RAMZA_ALGORITHM_EVENTS . " SET `processed_at`={$now} WHERE `id` IN ({$id_list})");
    return count($events);
}

function Wo_RamzaAlgorithmCronRun() {
    global $sqlConnect;
    if (!Wo_RamzaAlgorithmConfigSwitch('algorithm_system', '0') || !Wo_RamzaAlgorithmAdvancedAvailable()) {
        return array('status' => 'skipped', 'indexed' => 0, 'aggregated' => 0, 'cleaned' => 0);
    }
    $started = time();
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_CRON_STATUS)) {
        @mysqli_query($sqlConnect, "INSERT INTO " . T_RAMZA_ALGORITHM_CRON_STATUS . " (`job_name`,`status`,`started_at`) VALUES ('recommendation_maintenance','running',{$started}) ON DUPLICATE KEY UPDATE `status`='running',`started_at`=VALUES(`started_at`),`message`=''");
    }
    $indexed = Wo_RamzaAlgorithmIndexRecentPosts(160);
    $aggregated = Wo_RamzaAlgorithmAggregateEvents(800);
    $now = time();
    $cleaned = 0;
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_EVENTS)) {
        $event_cutoff = $now - (max(7, Wo_RamzaAlgorithmConfigNumber('algorithm_event_retention_days', 45)) * 86400);
        @mysqli_query($sqlConnect, "DELETE FROM " . T_RAMZA_ALGORITHM_EVENTS . " WHERE `created_at`<{$event_cutoff} LIMIT 5000");
        $cleaned += max(0, mysqli_affected_rows($sqlConnect));
    }
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_IMPRESSIONS)) {
        $seen_cutoff = $now - (max(2, Wo_RamzaAlgorithmConfigNumber('algorithm_impression_retention_days', 14)) * 86400);
        @mysqli_query($sqlConnect, "DELETE FROM " . T_RAMZA_ALGORITHM_IMPRESSIONS . " WHERE `last_seen_at`<{$seen_cutoff} LIMIT 5000");
        $cleaned += max(0, mysqli_affected_rows($sqlConnect));
    }
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_CRON_STATUS)) {
        $message = mysqli_real_escape_string($sqlConnect, "Indexed {$indexed}; aggregated {$aggregated}; cleaned {$cleaned}");
        @mysqli_query($sqlConnect, "UPDATE " . T_RAMZA_ALGORITHM_CRON_STATUS . " SET `status`='ok',`finished_at`={$now},`processed_count`=" . ($indexed + $aggregated + $cleaned) . ",`message`='{$message}' WHERE `job_name`='recommendation_maintenance'");
    }
    return array('status' => 'ok', 'indexed' => $indexed, 'aggregated' => $aggregated, 'cleaned' => $cleaned);
}

function Wo_RamzaAlgorithmAdminData($limit = 80) {
    global $sqlConnect;
    $limit = max(10, min(200, (int)$limit));
    $data = array('topics' => array(), 'relations' => array(), 'boosts' => array(), 'event_totals' => array());
    if (!Wo_RamzaAlgorithmAdvancedAvailable()) {
        return $data;
    }
    $alias_select = Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_TOPIC_ALIASES)
        ? ",(SELECT GROUP_CONCAT(a.`alias` ORDER BY a.`alias` SEPARATOR ', ') FROM " . T_RAMZA_ALGORITHM_TOPIC_ALIASES . " a WHERE a.`topic_id`=t.`id`) AS aliases"
        : ",'' AS aliases";
    $query = @mysqli_query($sqlConnect, "SELECT t.`id`,t.`parent_id`,t.`slug`,t.`name`,t.`topic_type`,t.`language`,t.`status`,t.`post_count`{$alias_select} FROM " . T_RAMZA_ALGORITHM_TOPICS . " t ORDER BY t.`status` DESC,t.`post_count` DESC,t.`name` ASC LIMIT {$limit}");
    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $data['topics'][] = $row;
        }
    }
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_TOPIC_RELATIONS)) {
        $query = @mysqli_query($sqlConnect, "SELECT r.`id`,r.`topic_id`,r.`related_topic_id`,r.`relation_type`,r.`weight`,a.`name` AS topic_name,b.`name` AS related_name FROM " . T_RAMZA_ALGORITHM_TOPIC_RELATIONS . " r INNER JOIN " . T_RAMZA_ALGORITHM_TOPICS . " a ON a.`id`=r.`topic_id` INNER JOIN " . T_RAMZA_ALGORITHM_TOPICS . " b ON b.`id`=r.`related_topic_id` ORDER BY r.`weight` DESC,r.`id` DESC LIMIT {$limit}");
        if ($query) {
            while ($row = mysqli_fetch_assoc($query)) {
                $data['relations'][] = $row;
            }
        }
    }
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_BOOSTS)) {
        $query = @mysqli_query($sqlConnect, "SELECT b.`id`,b.`post_id`,b.`topic_id`,b.`boost_type`,b.`weight`,b.`starts_at`,b.`ends_at`,b.`active`,t.`name` AS topic_name FROM " . T_RAMZA_ALGORITHM_BOOSTS . " b LEFT JOIN " . T_RAMZA_ALGORITHM_TOPICS . " t ON t.`id`=b.`topic_id` ORDER BY b.`active` DESC,b.`id` DESC LIMIT {$limit}");
        if ($query) {
            while ($row = mysqli_fetch_assoc($query)) {
                $data['boosts'][] = $row;
            }
        }
    }
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_EVENTS)) {
        $cutoff = time() - (7 * 86400);
        $query = @mysqli_query($sqlConnect, "SELECT `event_type`,COUNT(*) AS total FROM " . T_RAMZA_ALGORITHM_EVENTS . " WHERE `created_at`>={$cutoff} GROUP BY `event_type` ORDER BY total DESC LIMIT 30");
        if ($query) {
            while ($row = mysqli_fetch_assoc($query)) {
                $data['event_totals'][$row['event_type']] = (int)$row['total'];
            }
        }
    }
    return $data;
}

function Wo_RamzaAlgorithmSaveTopic($input) {
    global $sqlConnect;
    if (!Wo_RamzaAlgorithmAdvancedAvailable()) {
        return array('ok' => false, 'message' => 'Apply the advanced algorithm migration first.');
    }
    $topic_id = isset($input['topic_id']) && is_numeric($input['topic_id']) ? (int)$input['topic_id'] : 0;
    $name = trim(strip_tags((string)($input['name'] ?? '')));
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 190, 'UTF-8');
    } else {
        $name = substr($name, 0, 190);
    }
    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) < 2) {
        return array('ok' => false, 'message' => 'Enter a topic name.');
    }
    $slug_source = trim((string)($input['slug'] ?? '')) ?: $name;
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', Wo_RamzaAlgorithmNormalizeText($slug_source)), '-');
    if ($slug === '') {
        $slug = 'topic-' . substr(sha1($name), 0, 16);
    }
    $type = strtolower(trim((string)($input['topic_type'] ?? 'topic')));
    if (!in_array($type, array('category','topic','entity','creator','brand','hashtag','keyword'), true)) {
        $type = 'topic';
    }
    $language = strtolower(trim((string)($input['language'] ?? 'und')));
    if (!preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{2,8})?$/', $language) && $language !== 'und') {
        $language = 'und';
    }
    $status = !empty($input['status']) ? 1 : 0;
    $now = time();
    $safe_name = mysqli_real_escape_string($sqlConnect, $name);
    $safe_slug = mysqli_real_escape_string($sqlConnect, substr($slug, 0, 160));
    $safe_type = mysqli_real_escape_string($sqlConnect, $type);
    $safe_language = mysqli_real_escape_string($sqlConnect, $language);
    if ($topic_id > 0) {
        $query = @mysqli_query($sqlConnect, "UPDATE " . T_RAMZA_ALGORITHM_TOPICS . " SET `slug`='{$safe_slug}',`name`='{$safe_name}',`topic_type`='{$safe_type}',`language`='{$safe_language}',`status`={$status},`updated_at`={$now} WHERE `id`={$topic_id} LIMIT 1");
    } else {
        $query = @mysqli_query($sqlConnect, "INSERT INTO " . T_RAMZA_ALGORITHM_TOPICS . " (`parent_id`,`slug`,`name`,`topic_type`,`language`,`status`,`created_at`,`updated_at`) VALUES (0,'{$safe_slug}','{$safe_name}','{$safe_type}','{$safe_language}',{$status},{$now},{$now}) ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`status`=VALUES(`status`),`updated_at`=VALUES(`updated_at`)");
        $topic_id = (int)mysqli_insert_id($sqlConnect);
        if ($topic_id < 1) {
            $find = @mysqli_query($sqlConnect, "SELECT `id` FROM " . T_RAMZA_ALGORITHM_TOPICS . " WHERE `slug`='{$safe_slug}' AND `topic_type`='{$safe_type}' AND `language`='{$safe_language}' LIMIT 1");
            if ($find && ($row = mysqli_fetch_assoc($find))) {
                $topic_id = (int)$row['id'];
            }
        }
    }
    if (!$query || $topic_id < 1) {
        return array('ok' => false, 'message' => 'The topic could not be saved. Check for a duplicate slug.');
    }
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_TOPIC_ALIASES)) {
        @mysqli_query($sqlConnect, "DELETE FROM " . T_RAMZA_ALGORITHM_TOPIC_ALIASES . " WHERE `topic_id`={$topic_id}");
        $aliases = preg_split('/[,;\r\n]+/', (string)($input['aliases'] ?? ''));
        foreach (array_slice(array_unique(array_filter(array_map('trim', $aliases))), 0, 30) as $alias) {
            $normalized = Wo_RamzaAlgorithmNormalizeText($alias);
            if ($normalized === '') {
                continue;
            }
            $safe_alias = mysqli_real_escape_string($sqlConnect, function_exists('mb_substr') ? mb_substr($alias, 0, 190, 'UTF-8') : substr($alias, 0, 190));
            $safe_normalized = mysqli_real_escape_string($sqlConnect, function_exists('mb_substr') ? mb_substr($normalized, 0, 190, 'UTF-8') : substr($normalized, 0, 190));
            $collision = @mysqli_query($sqlConnect, "SELECT `id` FROM " . T_RAMZA_ALGORITHM_TOPIC_ALIASES . " WHERE `normalized_alias`='{$safe_normalized}' AND `language`='{$safe_language}' AND `topic_id`<>{$topic_id} LIMIT 1");
            if ($collision && mysqli_num_rows($collision)) {
                continue;
            }
            @mysqli_query($sqlConnect, "INSERT IGNORE INTO " . T_RAMZA_ALGORITHM_TOPIC_ALIASES . " (`topic_id`,`alias`,`normalized_alias`,`language`,`created_at`) VALUES ({$topic_id},'{$safe_alias}','{$safe_normalized}','{$safe_language}',{$now})");
        }
    }
    return array('ok' => true, 'message' => 'Topic saved.', 'topic_id' => $topic_id);
}

function Wo_RamzaAlgorithmSetTopicStatus($topic_id, $status) {
    global $sqlConnect;
    $topic_id = (int)$topic_id;
    if ($topic_id < 1 || !Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_TOPICS)) {
        return false;
    }
    return (bool)@mysqli_query($sqlConnect, "UPDATE " . T_RAMZA_ALGORITHM_TOPICS . " SET `status`=" . (!empty($status) ? 1 : 0) . ",`updated_at`=" . time() . " WHERE `id`={$topic_id} LIMIT 1");
}

function Wo_RamzaAlgorithmSaveRelation($input) {
    global $sqlConnect;
    if (!Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_TOPIC_RELATIONS)) {
        return array('ok' => false, 'message' => 'Topic relationships are not installed.');
    }
    $topic_id = isset($input['topic_id']) && is_numeric($input['topic_id']) ? (int)$input['topic_id'] : 0;
    $related_id = isset($input['related_topic_id']) && is_numeric($input['related_topic_id']) ? (int)$input['related_topic_id'] : 0;
    if ($topic_id < 1 || $related_id < 1 || $topic_id === $related_id) {
        return array('ok' => false, 'message' => 'Choose two different topics.');
    }
    $exists = @mysqli_query($sqlConnect, "SELECT COUNT(*) AS total FROM " . T_RAMZA_ALGORITHM_TOPICS . " WHERE `id` IN ({$topic_id},{$related_id})");
    $row = $exists ? mysqli_fetch_assoc($exists) : null;
    if (empty($row) || (int)$row['total'] !== 2) {
        return array('ok' => false, 'message' => 'One of the selected topics no longer exists.');
    }
    $type = strtolower(trim((string)($input['relation_type'] ?? 'related')));
    if (!in_array($type, array('related','similar','parent','category','brand','creator'), true)) {
        $type = 'related';
    }
    $weight = isset($input['weight']) && is_numeric($input['weight']) ? max(0, min(1, (float)$input['weight'])) : 0.5;
    $weight_sql = number_format($weight, 4, '.', '');
    $safe_type = mysqli_real_escape_string($sqlConnect, $type);
    $now = time();
    $save = static function ($from, $to) use ($sqlConnect, $safe_type, $weight_sql, $now) {
        return @mysqli_query($sqlConnect, "INSERT INTO " . T_RAMZA_ALGORITHM_TOPIC_RELATIONS . " (`topic_id`,`related_topic_id`,`relation_type`,`weight`,`created_at`) VALUES ({$from},{$to},'{$safe_type}',{$weight_sql},{$now}) ON DUPLICATE KEY UPDATE `weight`=VALUES(`weight`)");
    };
    $ok = $save($topic_id, $related_id);
    if ($ok && !empty($input['two_way'])) {
        $ok = $save($related_id, $topic_id);
    }
    return array('ok' => (bool)$ok, 'message' => $ok ? 'Relationship saved.' : 'The relationship could not be saved.');
}

function Wo_RamzaAlgorithmDeleteRelation($relation_id) {
    global $sqlConnect;
    $relation_id = (int)$relation_id;
    return $relation_id > 0 && Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_TOPIC_RELATIONS)
        ? (bool)@mysqli_query($sqlConnect, "DELETE FROM " . T_RAMZA_ALGORITHM_TOPIC_RELATIONS . " WHERE `id`={$relation_id} LIMIT 1")
        : false;
}

function Wo_RamzaAlgorithmSaveBoost($input, $admin_id) {
    global $sqlConnect;
    if (!Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_BOOSTS)) {
        return array('ok' => false, 'message' => 'Editorial boosts are not installed.');
    }
    $post_id = isset($input['post_id']) && is_numeric($input['post_id']) ? (int)$input['post_id'] : 0;
    $topic_id = isset($input['topic_id']) && is_numeric($input['topic_id']) ? (int)$input['topic_id'] : 0;
    if (($post_id > 0 && $topic_id > 0) || ($post_id < 1 && $topic_id < 1)) {
        return array('ok' => false, 'message' => 'Choose one post or one topic.');
    }
    $target_table = $post_id > 0 ? T_POSTS : T_RAMZA_ALGORITHM_TOPICS;
    $target_field = $post_id > 0 ? 'id' : 'id';
    $target_id = $post_id > 0 ? $post_id : $topic_id;
    $exists = @mysqli_query($sqlConnect, "SELECT `{$target_field}` FROM {$target_table} WHERE `{$target_field}`={$target_id} LIMIT 1");
    if (!$exists || mysqli_num_rows($exists) < 1) {
        return array('ok' => false, 'message' => 'The selected boost target does not exist.');
    }
    $weight = isset($input['weight']) && is_numeric($input['weight']) ? max(0, min(25, (float)$input['weight'])) : 1;
    $starts_at = !empty($input['starts_at']) ? strtotime((string)$input['starts_at']) : 0;
    $ends_at = !empty($input['ends_at']) ? strtotime((string)$input['ends_at']) : 0;
    $starts_at = $starts_at === false ? 0 : max(0, (int)$starts_at);
    $ends_at = $ends_at === false ? 0 : max(0, (int)$ends_at);
    if ($ends_at > 0 && $starts_at > 0 && $ends_at <= $starts_at) {
        return array('ok' => false, 'message' => 'The boost end must be after its start.');
    }
    $weight_sql = number_format($weight, 4, '.', '');
    $now = time();
    $admin_id = (int)$admin_id;
    $query = @mysqli_query($sqlConnect, "INSERT INTO " . T_RAMZA_ALGORITHM_BOOSTS . " (`post_id`,`topic_id`,`boost_type`,`weight`,`starts_at`,`ends_at`,`active`,`created_by`,`created_at`) VALUES ({$post_id},{$topic_id},'editorial',{$weight_sql},{$starts_at},{$ends_at},1,{$admin_id},{$now})");
    return array('ok' => (bool)$query, 'message' => $query ? 'Editorial boost saved.' : 'The boost could not be saved.');
}

function Wo_RamzaAlgorithmDeleteBoost($boost_id) {
    global $sqlConnect;
    $boost_id = (int)$boost_id;
    return $boost_id > 0 && Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_BOOSTS)
        ? (bool)@mysqli_query($sqlConnect, "DELETE FROM " . T_RAMZA_ALGORITHM_BOOSTS . " WHERE `id`={$boost_id} LIMIT 1")
        : false;
}

function Wo_RamzaAlgorithmStatus() {
    global $sqlConnect;
    $tables = array(
        'topics' => T_RAMZA_ALGORITHM_TOPICS,
        'post_topics' => T_RAMZA_ALGORITHM_POST_TOPICS,
        'interests' => T_RAMZA_ALGORITHM_USER_INTERESTS,
        'events' => T_RAMZA_ALGORITHM_EVENTS,
        'impressions' => T_RAMZA_ALGORITHM_IMPRESSIONS
    );
    $status = array('available' => Wo_RamzaAlgorithmAdvancedAvailable(), 'tables' => array(), 'last_cron' => null);
    foreach ($tables as $key => $table) {
        $exists = Wo_RamzaAlgorithmTableExists($table);
        $count = 0;
        if ($exists) {
            $query = @mysqli_query($sqlConnect, "SELECT COUNT(*) AS count FROM {$table}");
            if ($query && ($row = mysqli_fetch_assoc($query))) {
                $count = (int)$row['count'];
            }
        }
        $status['tables'][$key] = array('ready' => $exists, 'count' => $count);
    }
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_CRON_STATUS)) {
        $query = @mysqli_query($sqlConnect, "SELECT * FROM " . T_RAMZA_ALGORITHM_CRON_STATUS . " WHERE `job_name`='recommendation_maintenance' LIMIT 1");
        if ($query && mysqli_num_rows($query)) {
            $status['last_cron'] = mysqli_fetch_assoc($query);
        }
    }
    return $status;
}

function Wo_RamzaAlgorithmDebugUser($user_id, $limit = 12) {
    global $sqlConnect;
    $user_id = (int)$user_id;
    $limit = max(1, min(30, (int)$limit));
    $result = array('user_id' => $user_id, 'interests' => array(), 'recent_events' => array(), 'ranked_examples' => array());
    if (!Wo_RamzaAlgorithmAdvancedAvailable()) {
        return $result;
    }
    $query = @mysqli_query($sqlConnect, "SELECT ui.`topic_id`,t.`name`,t.`slug`,ui.`score`,ui.`positive_score`,ui.`negative_score`,ui.`last_event_at` FROM " . T_RAMZA_ALGORITHM_USER_INTERESTS . " ui LEFT JOIN " . T_RAMZA_ALGORITHM_TOPICS . " t ON t.`id`=ui.`topic_id` WHERE ui.`user_id`={$user_id} ORDER BY ABS(ui.`score`) DESC LIMIT {$limit}");
    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $result['interests'][] = $row;
        }
    }
    if (Wo_RamzaAlgorithmTableExists(T_RAMZA_ALGORITHM_EVENTS)) {
        $query = @mysqli_query($sqlConnect, "SELECT `post_id`,`event_type`,`strength`,`duration_ms`,`surface`,`processed_at`,`created_at` FROM " . T_RAMZA_ALGORITHM_EVENTS . " WHERE `user_id`={$user_id} ORDER BY `id` DESC LIMIT {$limit}");
        if ($query) {
            while ($row = mysqli_fetch_assoc($query)) {
                $result['recent_events'][] = $row;
            }
        }
        $post_query = @mysqli_query($sqlConnect, "SELECT `post_id`,MAX(`created_at`) AS last_event_at FROM " . T_RAMZA_ALGORITHM_EVENTS . " WHERE `user_id`={$user_id} AND `post_id`>0 GROUP BY `post_id` ORDER BY last_event_at DESC LIMIT {$limit}");
        $posts = array();
        if ($post_query) {
            while ($row = mysqli_fetch_assoc($post_query)) {
                $post = Wo_PostData((int)$row['post_id']);
                if (is_array($post) && !empty($post['id'])) {
                    $posts[] = $post;
                }
            }
        }
        if (!empty($posts)) {
            $ctx = Wo_RamzaAlgorithmBuildContext($user_id);
            $posts = Wo_RamzaAlgorithmEnrichPosts($posts, $ctx);
            foreach ($posts as $post) {
                $score = Wo_RamzaAlgorithmAdvancedScorePost($post, $ctx);
                $result['ranked_examples'][] = array(
                    'post_id' => (int)$post['id'],
                    'score' => round((float)$score['delta'], 4),
                    'pool' => $score['pool'],
                    'reason' => $score['reason'],
                    'breakdown' => array(
                        'topic' => round((float)$score['topic_match'], 4),
                        'related' => round((float)$score['related_match'], 4),
                        'collaborative' => round((float)$score['collaborative_match'], 4),
                        'negative' => round((float)$score['negative_match'], 4),
                        'quality' => round((float)$score['quality'], 4),
                        'seen_penalty' => round((float)$score['seen_penalty'], 4)
                    )
                );
            }
        }
    }
    return $result;
}
