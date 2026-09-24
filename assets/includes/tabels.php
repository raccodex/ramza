<?php
// +------------------------------------------------------------------------+
// | @author RacCodex (RacCodex)
// | @author_url 1: http://www.ramza.com
// | @author_url 2: http://codecanyon.net/user/RacCodex
// | @author_email: ramzasocial@gmail.com
// +------------------------------------------------------------------------+
// | ramza - The Ultimate Social Networking Platform
// | Copyright (c) 2022 ramza. All rights reserved.
// +------------------------------------------------------------------------+
if (!defined('RAC_TABLE_PREFIX')) {
    $rac_table_prefix = 'Wo_';
    if (isset($table_prefix) && is_string($table_prefix) && preg_match('/^[A-Za-z][A-Za-z0-9_]*_$/', $table_prefix)) {
        $rac_table_prefix = $table_prefix;
    }
    define('RAC_TABLE_PREFIX', $rac_table_prefix);
}
if (!function_exists('RacTable')) {
    function RacTable($name) {
        return RAC_TABLE_PREFIX . $name;
    }
}
if (!defined('T_THEME_SETTINGS')) {
    $rac_theme_settings_table = RAC_TABLE_PREFIX === 'Wo_' ? ('wonder' . 'tage_settings') : RacTable('ThemeSettings');
    define('T_THEME_SETTINGS', $rac_theme_settings_table);
}
define('T_USERS', RacTable('Users'));
define('T_COUNTRIES', RacTable('Countries'));
define('T_FOLLOWERS', RacTable('Followers'));
define('T_NOTIFICATION', RacTable('Notifications'));
define('T_MESSAGES', RacTable('Messages'));
define('T_BLOCKS', RacTable('Blocks'));
define('T_POSTS', RacTable('Posts'));
if (!defined('T_POST_VIEWS')) {
    define('T_POST_VIEWS', RacTable('PostViews'));
}
define('T_PINNED_POSTS', RacTable('PinnedPosts'));
define('T_LIKES', RacTable('Likes'));
define('T_SAVED_POSTS', RacTable('SavedPosts'));
define('T_WONDERS', RacTable('Wonders'));
define('T_COMMENTS', RacTable('Comments'));
define('T_COMMENT_WONDERS', RacTable('CommentWonders'));
define('T_COMMENT_LIKES', RacTable('CommentLikes'));
define('T_HASHTAGS', RacTable('Hashtags'));
define('T_REPORTS', RacTable('Reports'));
define('T_ADS', RacTable('Ads'));
define('T_ANNOUNCEMENT', RacTable('Announcement'));
define('T_ANNOUNCEMENT_VIEWS', RacTable('Announcement_Views'));
define('T_ACTIVITIES', RacTable('Activities'));
define('T_APPS', RacTable('Apps'));
define('T_APPS_PERMISSION', RacTable('Apps_Permission'));
define('T_TOKENS', RacTable('Tokens'));
define('T_PAGES', RacTable('Pages'));
define('T_PAGES_LIKES', RacTable('Pages_Likes'));
define('T_CONFIG', RacTable('Config'));
define('T_GROUPS', RacTable('Groups'));
define('T_GROUP_MEMBERS', RacTable('Group_Members'));
define('T_VERIFICATION_REQUESTS', RacTable('Verification_Requests'));
define('T_BANNED_IPS', RacTable('Banned_Ip'));
define('T_GAMES', RacTable('Games'));
define('T_GAMES_PLAYERS', RacTable('Games_Players'));
define('T_ALBUMS_MEDIA', RacTable('Albums_Media'));
define('T_COMMENTS_REPLIES', RacTable('Comment_Replies'));
define('T_COMMENT_REPLIES_LIKES', RacTable('Comment_Replies_Likes'));
define('T_COMMENT_REPLIES_WONDERS', RacTable('Comment_Replies_Wonders'));
define('T_RECENT_SEARCHES', RacTable('RecentSearches'));
define('T_PAGES_INVAITES', RacTable('Pages_Invites'));
define('T_TERMS', RacTable('Terms'));
define('T_EMAILS', RacTable('Emails'));
define('T_PAYMENTS', RacTable('Payments'));
define('T_CF_CATEGORIES', RacTable('Classified_Categories'));
define('T_VIDEOS_CALLES', RacTable('VideoCalles'));
define('T_AGORA', RacTable('AgoraVideoCall'));
define('T_APP_SESSIONS', RacTable('AppsSessions'));
define('T_FIELDS', RacTable('ProfileFields'));
define('T_USERS_FIELDS', RacTable('UserFields'));
define('T_PRODUCTS', RacTable('Products'));
define('T_PRODUCTS_MEDIA', RacTable('Products_Media'));
define('T_POLLS', RacTable('Polls'));
define('T_VOTES', RacTable('Votes'));
define('T_CUSTOM_PAGES', RacTable('CustomPages'));
define('T_A_REQUESTS', RacTable('Affiliates_Requests'));
define('T_U_CHATS', RacTable('UsersChat'));
define('T_APPS_HASH', RacTable('Apps_Hash'));
define('T_AUDIO_CALLES', RacTable('AudioCalls'));
define('T_LANGS', RacTable('Langs'));
define('T_CODES', RacTable('Codes'));
define('T_BLOG', RacTable('Blog'));
define('T_FORUM_SEC', RacTable('Forum_Sections'));
define('T_FORUM_THREADS', RacTable('Forum_Threads'));
define('T_FORUMS', RacTable('Forums'));
define('T_FORUM_THREAD_REPLIES', RacTable('ForumThreadReplies'));
define('T_EVENTS', RacTable('Events'));
define('T_EVENTS_INV', RacTable('Einvited'));
define('T_EVENTS_GOING', RacTable('Egoing'));
define('T_EVENTS_INT', RacTable('Einterested'));
define('T_MOVIES', RacTable('Movies'));
define('T_MOVIE_COMMS', RacTable('MovieComments'));
define('T_MOVIE_COMM_REPLIES', RacTable('MovieCommentReplies'));
define('T_BLOG_COMM', RacTable('BlogComments'));
define('T_BLOG_COMM_REPLIES', RacTable('BlogCommentReplies'));
define('T_USER_ADS', RacTable('UserAds'));
define('T_BM_LIKES', RacTable('BlogMovieLikes'));
define('T_BM_DISLIKES', RacTable('BlogMovieDisLikes'));
define('T_USER_STORY', RacTable('UserStory'));
define('T_USER_STORY_MEDIA', RacTable('UserStoryMedia'));
define('T_HIDDEN_POSTS', RacTable('HiddenPosts'));
// Advanced recommendation data is additive and remains part of the existing
// Algorithm Control feature. These names intentionally do not replace any Wo_*
// compatibility table used by the core product.
define('T_RAMZA_ALGORITHM_TOPICS', 'Ramza_AlgorithmTopics');
define('T_RAMZA_ALGORITHM_TOPIC_ALIASES', 'Ramza_AlgorithmTopicAliases');
define('T_RAMZA_ALGORITHM_TOPIC_RELATIONS', 'Ramza_AlgorithmTopicRelations');
define('T_RAMZA_ALGORITHM_POST_TOPICS', 'Ramza_AlgorithmPostTopics');
define('T_RAMZA_ALGORITHM_USER_INTERESTS', 'Ramza_AlgorithmUserInterests');
define('T_RAMZA_ALGORITHM_EVENTS', 'Ramza_AlgorithmEvents');
define('T_RAMZA_ALGORITHM_IMPRESSIONS', 'Ramza_AlgorithmImpressions');
define('T_RAMZA_ALGORITHM_BOOSTS', 'Ramza_AlgorithmBoosts');
define('T_RAMZA_ALGORITHM_CRON_STATUS', 'Ramza_AlgorithmCronStatus');
define('T_INVITATIONS', RacTable('AdminInvitations'));
define('T_GROUP_ADMINS', RacTable('GroupAdmins'));
define('T_PAGE_ADMINS', RacTable('PageAdmins'));
define('T_GROUP_CHAT', RacTable('GroupChat'));
define('T_GROUP_CHAT_USERS', RacTable('GroupChatUsers'));
define('T_PAGE_RATING', RacTable('PageRating'));
define('T_FAMILY', RacTable('Family'));
define('T_REL_SHIP', RacTable('Relationship'));
define('T_PAYMENT_TRANSACTIONS', RacTable('Payment_Transactions'));
define('T_USERADS_DATA', RacTable('UserAds_Data'));
define('T_POKES', RacTable('Pokes'));
define('T_GIFTS', RacTable('Gifts'));
define('T_USERGIFTS', RacTable('User_Gifts'));
define('T_STICKERS', RacTable('Stickers'));
define('T_REACTIONS', RacTable('Reactions'));
define('T_STORY_SEEN', RacTable('Story_Seen'));
define('T_MANAGE_PRO', RacTable('Manage_Pro'));
define('T_PAGES_CATEGORY', RacTable('Pages_Categories'));
define('T_GROUPS_CATEGORY', RacTable('Groups_Categories'));
define('T_BLOGS_CATEGORY', RacTable('Blogs_Categories'));
define('T_PRODUCTS_CATEGORY', RacTable('Products_Categories'));
define('T_JOB_CATEGORY', RacTable('Job_Categories'));
define('T_BANK_TRANSFER', 'bank_receipts');
define('T_COLORS', RacTable('Colored_Posts'));
define('T_ADMIN', RacTable('Admin_Pages'));
define('T_GENDER', RacTable('Gender'));
define('T_JOB', RacTable('Job'));
define('T_JOB_APPLY', RacTable('Job_Apply'));
define('T_BLOG_REACTION', RacTable('Blog_Reaction'));
define('T_FUNDING', RacTable('Funding'));
define('T_FUNDING_RAISE', RacTable('Funding_Raise'));
define('T_REACTIONS_TYPES', RacTable('Reactions_Types'));
define('T_SUB_CATEGORIES', RacTable('Sub_Categories'));
define('T_CUSTOM_FIELDS', RacTable('Custom_Fields'));
define('T_REFUND', RacTable('Refund'));
define('T_OFFER', RacTable('Offers'));
define('T_BAD_LOGIN', RacTable('Bad_Login'));
define('T_INVITAION_LINKS', RacTable('Invitation_Links'));
define('T_LIVE_SUB', RacTable('Live_Sub_Users'));
define('T_MUTE', RacTable('Mute'));
define('T_MUTE_STORY', RacTable('Mute_Story'));
define('T_CAST', 'broadcast');
define('T_CAST_USERS', 'broadcast_users');
define('T_HTML_EMAILS', RacTable('HTML_Emails'));
define('T_USERCARD', RacTable('UserCard'));
define('T_USER_ADDRESS', RacTable('UserAddress'));
define('T_USER_ORDERS', RacTable('UserOrders'));
define('T_PURCHAES', RacTable('Purchases'));
define('T_PRODUCT_REVIEW', RacTable('ProductReview'));
define('T_PATREON_SUBSCRIBERS', RacTable('PatreonSubscribers'));
define('T_USER_EXPERIENCE', RacTable('UserExperience'));
define('T_USER_CERTIFICATION', RacTable('UserCertification'));
define('T_USER_MONETIZATION', RacTable('UserMonetization'));
define('T_MONETIZATION_SUBSCRIBTION', RacTable('MonetizationSubscription'));
define('T_USER_PROJECTS', RacTable('UserProjects'));
define('T_USER_OPEN_TO', RacTable('UserOpenTo'));
define('T_USER_TIERS', RacTable('UserTiers'));
define('T_USER_SKILLS', RacTable('UserSkills'));
define('T_USER_LANGUAGES', RacTable('UserLanguages'));
define('T_LANG_ISO', RacTable('LangIso'));
define('T_PENDING_PAYMENTS', RacTable('PendingPayments'));
define('T_UPLOADED_MEDIA', RacTable('UploadedMedia'));
define('T_BACKUP_CODES', RacTable('Backup_Codes'));
define('T_RAMZA_WEBRTC_SIGNALS', RacTable('Ramza_WebRTC_Signals'));
// define('T_COUNTRIES_ADS', RacTable('CountriesAds'));

?>
