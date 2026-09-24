<?php

$testConfig = array(
    'algorithm_relevance_mode' => 'balanced',
    'algorithm_rank_topic_match' => 34,
    'algorithm_pool_related' => 10,
    'algorithm_pool_collaborative' => 5,
    'algorithm_rank_creator_affinity' => 20,
    'algorithm_rank_quality' => 12,
    'algorithm_rank_network' => 18,
    'algorithm_rank_boost_cap' => 8,
    'algorithm_rank_seen_penalty' => 35,
    'algorithm_trending_window_hours' => 24,
    'algorithm_pool_followed' => 40,
    'algorithm_pool_interest' => 30,
    'algorithm_pool_related' => 10,
    'algorithm_pool_collaborative' => 5,
    'algorithm_pool_trending' => 5,
    'algorithm_pool_new_creator' => 5,
    'algorithm_pool_exploration' => 5,
    'algorithm_signal_hide_event' => -3,
    'algorithm_signal_report_event' => -4,
    'algorithm_signal_block_event' => -5,
    'algorithm_signal_like_event' => 1,
    'algorithm_signal_save_event' => 2.1,
    'algorithm_signal_share_event' => 2.4
);

function Wo_RamzaAlgorithmConfigValue($key, $default = '') {
    global $testConfig;
    return array_key_exists($key, $testConfig) ? $testConfig[$key] : $default;
}
function Wo_RamzaAlgorithmConfigNumber($key, $default = 0) {
    return (float)Wo_RamzaAlgorithmConfigValue($key, $default);
}
function Wo_RamzaAlgorithmConfigSwitch($key, $default = '1') {
    return (string)Wo_RamzaAlgorithmConfigValue($key, $default) === '1';
}

require dirname(__DIR__) . '/assets/includes/ramza_algorithm.php';

$passed = 0;
$failed = 0;
$assert = function ($condition, $message) use (&$passed, &$failed) {
    if ($condition) {
        $passed++;
        echo "PASS: {$message}\n";
    } else {
        $failed++;
        echo "FAIL: {$message}\n";
    }
};

$baseContext = array(
    'topic_affinity' => array(),
    'related_topic_affinity' => array(),
    'collaborative_topic_affinity' => array(),
    'seen_posts' => array(),
    'following' => array(),
    'liked_pages' => array(),
    'joined_groups' => array(),
    'author_affinity' => array()
);
$basePost = array(
    'id' => 1, 'user_id' => 50, 'page_id' => 0, 'group_id' => 0,
    'time' => time() - 3600, 'post_likes' => 2, 'post_wonders' => 0,
    'post_comments' => 1, 'post_shares' => 0, 'videoViews' => 0,
    '_ramza_algorithm_boost' => 0
);

// Elon Musk -> Tesla / SpaceX related-interest acceptance case.
$elonContext = $baseContext;
$elonContext['topic_affinity'][5] = 7;
$elonContext['related_topic_affinity'][4] = 6.3;
$elonContext['related_topic_affinity'][6] = 6.3;
$tesla = $basePost;
$tesla['id'] = 101;
$tesla['_ramza_algorithm_topics'] = array(4 => 0.95);
$generic = $basePost;
$generic['id'] = 102;
$generic['_ramza_algorithm_topics'] = array();
$teslaScore = Wo_RamzaAlgorithmAdvancedScorePost($tesla, $elonContext);
$genericScore = Wo_RamzaAlgorithmAdvancedScorePost($generic, $elonContext);
$assert($teslaScore['delta'] > $genericScore['delta'], 'Elon interest promotes related Tesla content.');
$assert($teslaScore['pool'] === 'related', 'Tesla candidate is attributed to the related-topic pool.');

// Repeated dance engagement produces a direct-interest advantage.
$danceContext = $baseContext;
$danceContext['topic_affinity'][7] = 9;
$dance = $basePost;
$dance['id'] = 201;
$dance['_ramza_algorithm_topics'] = array(7 => 0.9);
$danceScore = Wo_RamzaAlgorithmAdvancedScorePost($dance, $danceContext);
$assert($danceScore['topic_match'] > 0.7 && $danceScore['pool'] === 'interest', 'Repeated dance engagement creates strong direct-topic relevance.');

// Negative comedy feedback outranks positive popularity pressure.
$negativeContext = $baseContext;
$negativeContext['topic_affinity'][9] = -10;
$comedy = $basePost;
$comedy['id'] = 301;
$comedy['post_likes'] = 100;
$comedy['_ramza_algorithm_topics'] = array(9 => 1);
$comedyScore = Wo_RamzaAlgorithmAdvancedScorePost($comedy, $negativeContext);
$assert($comedyScore['negative_match'] > 0.8, 'Hide/report feedback creates strong negative topic affinity.');

// Attitude and motivation relationship case.
$motivationContext = $baseContext;
$motivationContext['topic_affinity'][10] = 7;
$motivationContext['related_topic_affinity'][11] = 5;
$attitude = $basePost;
$attitude['id'] = 401;
$attitude['_ramza_algorithm_topics'] = array(11 => 0.9);
$attitudeScore = Wo_RamzaAlgorithmAdvancedScorePost($attitude, $motivationContext);
$assert($attitudeScore['related_match'] > 0.5, 'Motivation interest promotes related attitude content.');

// Recent impressions must materially reduce a repeated post.
$seenContext = $danceContext;
$seenContext['seen_posts'][201] = array('time' => time() - 60, 'count' => 3);
$seenDance = Wo_RamzaAlgorithmAdvancedScorePost($dance, $seenContext);
$assert($seenDance['seen_penalty'] > 0.6 && $seenDance['delta'] < $danceScore['delta'], 'Recently repeated posts receive a strong seen-history penalty.');

// Cold-start users keep a safe trending/new-creator path.
$cold = Wo_RamzaAlgorithmAdvancedScorePost($generic, $baseContext);
$assert(in_array($cold['pool'], array('trending','new_creator','exploration'), true), 'Cold start falls back to discovery pools without an empty result.');

// Composition includes discovery while preserving all candidates exactly once.
$ranked = array();
foreach (array('followed','followed','followed','interest','interest','related','trending','new_creator','exploration','collaborative') as $i => $pool) {
    $ranked[] = array('id' => $i + 1, '_ramza_algorithm_pool' => $pool);
}
$composed = Wo_RamzaAlgorithmComposeFeed($ranked, 8);
$headPools = array_column(array_slice($composed, 0, 8), '_ramza_algorithm_pool');
$assert(count(array_unique(array_column($composed, 'id'))) === count($ranked), 'Pool composition preserves every candidate without duplicates.');
$assert(in_array('exploration', $headPools, true) || in_array('new_creator', $headPools, true), 'Pool composition keeps discovery in the visible batch.');

$testConfig['algorithm_relevance_mode'] = 'very_strict';
$strictPools = array(
    array('id' => 21, '_ramza_algorithm_pool' => 'followed'),
    array('id' => 22, '_ramza_algorithm_pool' => 'interest'),
    array('id' => 23, '_ramza_algorithm_pool' => 'related'),
    array('id' => 24, '_ramza_algorithm_pool' => 'collaborative'),
    array('id' => 25, '_ramza_algorithm_pool' => 'exploration')
);
$strictComposed = Wo_RamzaAlgorithmComposeFeed($strictPools, 4);
$assert(!in_array('exploration', array_column(array_slice($strictComposed, 0, 4), '_ramza_algorithm_pool'), true), 'Very strict composition excludes discovery when relevant candidates are available.');
$testConfig['algorithm_relevance_mode'] = 'balanced';

// Strict mode penalizes unmatched content but does not delete it.
$testConfig['algorithm_relevance_mode'] = 'very_strict';
$strictGeneric = Wo_RamzaAlgorithmAdvancedScorePost($generic, $danceContext);
$testConfig['algorithm_relevance_mode'] = 'balanced';
$assert($strictGeneric['delta'] < $genericScore['delta'], 'Very strict mode down-ranks unmatched content.');

// Admin validation and negative-signal safety.
$assert(Wo_RamzaAlgorithmValidateAdminSetting('algorithm_relevance_mode', 'invalid') === 'balanced', 'Invalid strictness values fall back safely.');
$assert(Wo_RamzaAlgorithmValidateAdminSetting('algorithm_rank_topic_match', 999) === '100', 'Admin numeric weights are clamped.');
$assert(Wo_RamzaAlgorithmValidateAdminSetting('algorithm_unknown_setting', '1') === null, 'Unknown algorithm settings are rejected.');
$weights = Wo_RamzaAlgorithmEventWeights();
$assert($weights['hide'] < 0 && $weights['report'] < $weights['hide'] && $weights['block'] < $weights['report'], 'Hide, report and block signals have escalating negative strength.');
$requiredEvents = array('impression','click','open_post','like','reaction','comment','reply','share','repost','save','follow_after_view','profile_visit','watch_25','watch_50','watch_75','watch_complete','rewatch','sound_on','dwell','expand_text','open_comments','search_topic','hashtag_click','send_post','quick_skip','short_watch','hide','not_interested','unfollow','mute','report','block');
$assert(count(array_diff($requiredEvents, array_keys($weights))) === 0, 'The behavior vocabulary covers positive, watch-depth and negative feedback signals.');

// Static privacy/schema gates prevent regressions without touching a database.
$feedSource = file_get_contents(dirname(__DIR__) . '/assets/includes/functions_one.php');
$migration = file_get_contents(dirname(__DIR__) . '/updates/ramza_algorithm_advanced.sql');
$assert(strpos($feedSource, "T_BLOCKS") !== false && strpos($feedSource, "postPrivacy` = '0'") !== false, 'Discovery candidates retain explicit block and public-privacy gates.');
$assert(strpos($feedSource, "INNER JOIN " . '" . T_USERS . "' ) !== false || strpos($feedSource, "u.`active` = '1'") !== false, 'Discovery candidates retain active-publisher moderation gates.');
$assert(strpos($feedSource, 'Safety-classified candidates are removed') !== false, 'Sensitive candidates cannot become an empty-feed fallback.');
foreach (array('Topics','TopicRelations','PostTopics','UserInterests','Events','Impressions','Boosts','CronStatus') as $tableSuffix) {
    $assert(strpos($migration, 'Ramza_Algorithm' . $tableSuffix) !== false, 'Migration defines Ramza_Algorithm' . $tableSuffix . '.');
}

echo "RESULT: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
