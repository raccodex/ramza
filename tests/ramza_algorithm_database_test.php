<?php

// Mutating integration test. It refuses to run unless the target name clearly
// identifies a disposable algorithm database.

$database = $argv[1] ?? '';
if (!preg_match('/^ramza_algorithm_disposable_[0-9]{8}_[0-9]{6}$/', $database)) {
    fwrite(STDERR, "Refusing to run: pass a clearly named ramza_algorithm_disposable_YYYYMMDD_HHMMSS database.\n");
    exit(2);
}

$sqlConnect = @mysqli_connect('127.0.0.1', 'root', '', $database, 3306);
if (!$sqlConnect) {
    fwrite(STDERR, "Unable to connect to the disposable database.\n");
    exit(2);
}
mysqli_set_charset($sqlConnect, 'utf8mb4');

define('T_RAMZA_ALGORITHM_TOPICS', 'Ramza_AlgorithmTopics');
define('T_RAMZA_ALGORITHM_TOPIC_ALIASES', 'Ramza_AlgorithmTopicAliases');
define('T_RAMZA_ALGORITHM_TOPIC_RELATIONS', 'Ramza_AlgorithmTopicRelations');
define('T_RAMZA_ALGORITHM_POST_TOPICS', 'Ramza_AlgorithmPostTopics');
define('T_RAMZA_ALGORITHM_USER_INTERESTS', 'Ramza_AlgorithmUserInterests');
define('T_RAMZA_ALGORITHM_EVENTS', 'Ramza_AlgorithmEvents');
define('T_RAMZA_ALGORITHM_IMPRESSIONS', 'Ramza_AlgorithmImpressions');
define('T_RAMZA_ALGORITHM_BOOSTS', 'Ramza_AlgorithmBoosts');
define('T_RAMZA_ALGORITHM_CRON_STATUS', 'Ramza_AlgorithmCronStatus');
define('T_POSTS', 'Wo_Posts');

$testConfig = array(
    'algorithm_system' => '1',
    'algorithm_advanced_topics' => '1',
    'algorithm_interest_decay_days' => '21',
    'algorithm_candidate_topic_limit' => '12',
    'algorithm_track_impressions' => '1',
    'algorithm_candidate_seen_hours' => '48',
    'algorithm_rank_boost_cap' => '8',
    'algorithm_topic_limit_per_post' => '8',
    'algorithm_relevance_mode' => 'balanced',
    'algorithm_rank_topic_match' => '34',
    'algorithm_rank_related_topic' => '18',
    'algorithm_rank_collaborative' => '10',
    'algorithm_rank_creator_affinity' => '20',
    'algorithm_rank_quality' => '12',
    'algorithm_rank_network' => '18',
    'algorithm_rank_negative_topic' => '42',
    'algorithm_rank_seen_penalty' => '35'
);
$wo = array('config' => &$testConfig, 'user' => array('language' => 'english'));

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
$assert = static function ($condition, $message) use (&$passed, &$failed) {
    if ($condition) {
        $passed++;
        echo "PASS: {$message}\n";
    } else {
        $failed++;
        echo "FAIL: {$message}\n";
    }
};
$scalar = static function ($sql) use ($sqlConnect) {
    $query = mysqli_query($sqlConnect, $sql);
    if (!$query) {
        throw new RuntimeException(mysqli_error($sqlConnect));
    }
    $row = mysqli_fetch_row($query);
    return $row[0] ?? null;
};

$userId = 99001401;
$postId = 99001401;
$creatorId = 99001402;
$now = time();

try {
    $elonId = (int)$scalar("SELECT `id` FROM Ramza_AlgorithmTopics WHERE `slug`='elon-musk'");
    $teslaId = (int)$scalar("SELECT `id` FROM Ramza_AlgorithmTopics WHERE `slug`='tesla'");
    $danceId = (int)$scalar("SELECT `id` FROM Ramza_AlgorithmTopics WHERE `slug`='dance'");
    $assert($elonId > 0 && $teslaId > 0 && $danceId > 0, 'Required normalized seed topics exist.');

    mysqli_query($sqlConnect, "DELETE FROM Ramza_AlgorithmEvents WHERE `user_id`={$userId}");
    mysqli_query($sqlConnect, "DELETE FROM Ramza_AlgorithmUserInterests WHERE `user_id`={$userId}");
    mysqli_query($sqlConnect, "DELETE FROM Ramza_AlgorithmPostTopics WHERE `post_id`={$postId}");
    mysqli_query($sqlConnect, "DELETE FROM Ramza_AlgorithmBoosts WHERE `created_by`={$userId}");

    mysqli_query($sqlConnect, "INSERT INTO Ramza_AlgorithmUserInterests (`user_id`,`topic_id`,`score`,`positive_score`,`negative_score`,`event_count`,`last_event_at`,`updated_at`) VALUES ({$userId},{$elonId},12,12,0,3,{$now},{$now})");
    mysqli_query($sqlConnect, "INSERT INTO Ramza_AlgorithmPostTopics (`post_id`,`topic_id`,`source`,`confidence`,`created_at`,`updated_at`) VALUES ({$postId},{$danceId},'test',1,{$now},{$now})");
    mysqli_query($sqlConnect, "INSERT INTO Ramza_AlgorithmEvents (`user_id`,`post_id`,`event_type`,`strength`,`event_key`,`created_at`) VALUES ({$userId},{$postId},'comment',5,SHA1(CONCAT('db-test-',{$now})),{$now})");

    $processed = Wo_RamzaAlgorithmAggregateEvents(50);
    $danceScore = (float)$scalar("SELECT `score` FROM Ramza_AlgorithmUserInterests WHERE `user_id`={$userId} AND `topic_id`={$danceId}");
    $assert($processed >= 1 && $danceScore >= 4.99, 'Queued behavior aggregates into the user topic profile.');
    $unprocessed = (int)$scalar("SELECT COUNT(*) FROM Ramza_AlgorithmEvents WHERE `user_id`={$userId} AND `processed_at`=0");
    $assert($unprocessed === 0, 'Aggregated events are marked processed exactly once.');

    $context = Wo_RamzaAlgorithmBuildAdvancedContext($userId, array('following' => array(), 'liked_pages' => array(), 'joined_groups' => array(), 'author_affinity' => array()));
    $teslaPost = array('id' => $postId + 1, 'user_id' => $creatorId, 'time' => $now, '_ramza_algorithm_topics' => array($teslaId => 1));
    $score = Wo_RamzaAlgorithmAdvancedScorePost($teslaPost, $context);
    $assert($score['related_match'] > 0 && $score['pool'] === 'related', 'Elon affinity produces a related Tesla recommendation through the graph.');

    mysqli_query($sqlConnect, "INSERT INTO Ramza_AlgorithmBoosts (`topic_id`,`weight`,`active`,`created_by`,`created_at`) VALUES ({$teslaId},4,1,{$userId},{$now})");
    $enriched = Wo_RamzaAlgorithmEnrichPosts(array(array('id' => $postId + 1, 'postText' => 'Tesla innovation')), $context);
    $assert(!empty($enriched[0]['_ramza_algorithm_boost']) && (float)$enriched[0]['_ramza_algorithm_boost'] >= 4, 'A topic boost is applied to matching candidates without a per-post query.');

    $status = Wo_RamzaAlgorithmStatus();
    $assert(!empty($status['available']) && !empty($status['tables']['events']['ready']), 'Operational status reports the installed engine and event store.');

    mysqli_query($sqlConnect, "DELETE a FROM Ramza_AlgorithmTopicAliases a INNER JOIN Ramza_AlgorithmTopics t ON t.id=a.topic_id WHERE t.slug='qa-disposable-topic'");
    mysqli_query($sqlConnect, "DELETE r FROM Ramza_AlgorithmTopicRelations r INNER JOIN Ramza_AlgorithmTopics t ON t.id=r.topic_id OR t.id=r.related_topic_id WHERE t.slug='qa-disposable-topic'");
    mysqli_query($sqlConnect, "DELETE FROM Ramza_AlgorithmTopics WHERE slug='qa-disposable-topic'");
    $topicResult = Wo_RamzaAlgorithmSaveTopic(array('name' => 'QA Disposable Topic', 'slug' => 'qa-disposable-topic', 'topic_type' => 'topic', 'language' => 'und', 'aliases' => 'QA Topic, Test Topic', 'status' => 1));
    $qaTopicId = (int)($topicResult['topic_id'] ?? 0);
    $aliasCount = $qaTopicId > 0 ? (int)$scalar("SELECT COUNT(*) FROM Ramza_AlgorithmTopicAliases WHERE topic_id={$qaTopicId}") : 0;
    $assert(!empty($topicResult['ok']) && $qaTopicId > 0 && $aliasCount === 2, 'Admin topic management saves a normalized topic and aliases.');

    $relationResult = Wo_RamzaAlgorithmSaveRelation(array('topic_id' => $qaTopicId, 'related_topic_id' => $danceId, 'relation_type' => 'related', 'weight' => 0.73, 'two_way' => 1));
    $relationCount = (int)$scalar("SELECT COUNT(*) FROM Ramza_AlgorithmTopicRelations WHERE (topic_id={$qaTopicId} AND related_topic_id={$danceId}) OR (topic_id={$danceId} AND related_topic_id={$qaTopicId})");
    $assert(!empty($relationResult['ok']) && $relationCount === 2, 'Admin relationship management saves an optional two-way graph edge.');

    $boostResult = Wo_RamzaAlgorithmSaveBoost(array('topic_id' => $qaTopicId, 'weight' => 2.5), $userId);
    $assert(!empty($boostResult['ok']), 'Admin editorial boost management accepts a valid topic target.');
    $adminData = Wo_RamzaAlgorithmAdminData(200);
    $assert(count($adminData['topics']) >= 27 && is_array($adminData['relations']) && is_array($adminData['boosts']), 'Algorithm Control operational data loads topics, relationships and boosts in batches.');
} catch (Throwable $error) {
    $failed++;
    echo 'FAIL: Database test raised ' . $error->getMessage() . "\n";
} finally {
    mysqli_query($sqlConnect, "DELETE FROM Ramza_AlgorithmEvents WHERE `user_id`={$userId}");
    mysqli_query($sqlConnect, "DELETE FROM Ramza_AlgorithmUserInterests WHERE `user_id`={$userId}");
    mysqli_query($sqlConnect, "DELETE FROM Ramza_AlgorithmPostTopics WHERE `post_id`={$postId}");
    mysqli_query($sqlConnect, "DELETE FROM Ramza_AlgorithmBoosts WHERE `created_by`={$userId}");
    mysqli_query($sqlConnect, "DELETE a FROM Ramza_AlgorithmTopicAliases a INNER JOIN Ramza_AlgorithmTopics t ON t.id=a.topic_id WHERE t.slug='qa-disposable-topic'");
    mysqli_query($sqlConnect, "DELETE r FROM Ramza_AlgorithmTopicRelations r INNER JOIN Ramza_AlgorithmTopics t ON t.id=r.topic_id OR t.id=r.related_topic_id WHERE t.slug='qa-disposable-topic'");
    mysqli_query($sqlConnect, "DELETE FROM Ramza_AlgorithmTopics WHERE slug='qa-disposable-topic'");
}

echo "RESULT: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
