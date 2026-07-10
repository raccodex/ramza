const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")


const { AvatarChangedController } = require('../controllers/AvatarChangedController');
const { JoinController } = require('../controllers/JoinController');
const { PingForLastseenController } = require('../controllers/PingForLastseenController');
const { CloseChatController } = require('../controllers/CloseChatController');
const { IsChatOnController } = require('../controllers/IsChatOnController');
const { PageMessageController } = require('../controllers/PageMessageController');
const { GroupMessageController } = require('../controllers/GroupMessageController');
const { GroupMessagePageController } = require('../controllers/GroupMessagePageController');
const { PrivateMessagePageController } = require('../controllers/PrivateMessagePageController');
const { ActiveMessageUserChangeController } = require('../controllers/ActiveMessageUserChangeController');
const { TypingController } = require('../controllers/TypingController');
const { RecordingController } = require('../controllers/RecordingController');
const { TypingDoneController } = require('../controllers/TypingDoneController');
const { GetReactionController } = require('../controllers/GetReactionController');
const { ColorChangeController } = require('../controllers/ColorChangeController');
const { SyncGroupsController } = require('../controllers/SyncGroupsController');
const { MuteController } = require('../controllers/MuteController');
const { PrivateMessageController } = require('../controllers/PrivateMessageController');
const { LoadmoreController } = require('../controllers/LoadmoreController');
const { LoadmorePageController } = require('../controllers/LoadmorePageController');
const { LoadmoreGroupController } = require('../controllers/LoadmoreGroupController');
const { LoadmoreGroupPageController } = require('../controllers/LoadmoreGroupPageController');
const { OnNameChangedController } = require('../controllers/OnNameChangedController');
const { OnUserLoggedinController } = require('../controllers/OnUserLoggedinController');
const { EventNotificationController } = require('../controllers/EventNotificationController');
const { GroupNotificationController } = require('../controllers/GroupNotificationController');
const { PageNotificationController } = require('../controllers/PageNotificationController');
const { UserFollowersNotificationController } = require('../controllers/UserFollowersNotificationController');
const { UpdateNewPostsController } = require('../controllers/UpdateNewPostsController');
const { DeclineCallController } = require('../controllers/DeclineCallController');
const { UserNotificationController } = require('../controllers/UserNotificationController');
const { RegisterReactionController } = require('../controllers/RegisterReactionController');
const { CheckoutNotificationController } = require('../controllers/CheckoutNotificationController');
const { MainNotificationController } = require('../controllers/MainNotificationController');
const { PostNotificationController } = require('../controllers/PostNotificationController');
const { CommentNotificationController } = require('../controllers/CommentNotificationController');
const { ReplyNotificationController } = require('../controllers/ReplyNotificationController');
const { OnUserLoggedoffController } = require('../controllers/OnUserLoggedoffController');
const { SeenMessagesController } = require('../controllers/SeenMessagesController');
const { DisconnectController } = require('../controllers/DisconnectController');
const { NotificationsController } = require('../controllers/NotificationsController');
const { ColorChangedController } = require('../controllers/ColorChangedController');



module.exports.registerListeners = async (socket, io, ctx) => {

    console.log('a user connected ' + socket.id + " Hash " + JSON.stringify(socket.handshake.query));

    await compiledTemplates.DefineTemplates(ctx);
    ctx.reactions_types = await funcs.Wo_GetReactionsTypes(ctx);

    socket.on("join", async (data, callback) => {
        JoinController(ctx, data, io,socket,callback);
    })

    socket.on("ping_for_lastseen", async (data) => {
        PingForLastseenController(ctx, data, io,socket);
    })

    socket.on("close_chat", async (data) => {
        CloseChatController(ctx, data, io,socket);
    })

    socket.on("is_chat_on", async (data) => {
        IsChatOnController(ctx, data, io,socket);
    })

    socket.on("page_message", async (data, callback) => {
        PageMessageController(ctx, data, io,socket,callback);
    })

    socket.on("group_message", async (data, callback) => {
        GroupMessageController(ctx, data, io,socket,callback);
    })

    socket.on("group_message_page", async (data, callback) => {
        GroupMessagePageController(ctx, data, io,socket,callback);
    })

    socket.on("private_message_page", async (data, callback) => {
        PrivateMessagePageController(ctx, data, io,socket,callback);
    })

    socket.on("active-message-user-change", async (data) => {
        ActiveMessageUserChangeController(ctx, data, io,socket);
    })

    socket.on('typing', async (data) => {
        TypingController(ctx, data, io,socket);
    })
    
    socket.on('recording', async (data) => {
        RecordingController(ctx, data, io,socket);
    })

    socket.on('typing_done', async (data) => {
        TypingDoneController(ctx, data, io,socket);
    })

    socket.on('get_reaction', async (data) => {
        GetReactionController(ctx, data, io,socket);
    })

    socket.on("color-change", async (data) => {
        ColorChangeController(ctx, data, io,socket);
    })

    socket.on("sync_groups", async (data) => {
        SyncGroupsController(ctx, data, io,socket);
    })

    socket.on("mute", async (data, callback) => {
        MuteController(ctx, data, io,socket,callback);
    })

    socket.on("private_message", async (data, callback) => {
        PrivateMessageController(ctx, data, io,socket,callback);
    })

    socket.on("loadmore", async (data, callback) => {
        LoadmoreController(ctx, data, io,socket,callback);
    })

    socket.on("loadmore_page", async (data, callback) => {
        LoadmorePageController(ctx, data, io,socket,callback);
    })

    socket.on("loadmore_group", async (data, callback) => {
        LoadmoreGroupController(ctx, data, io,socket,callback);
    })

    socket.on("loadmore_group_page", async (data, callback) => {
        LoadmoreGroupPageController(ctx, data, io,socket,callback);
    })

    socket.on("on_name_changed", async (data) => {
        OnNameChangedController(ctx, data, io,socket);
    })

    socket.on("on_avatar_changed", async (data) => {
        AvatarChangedController(ctx, data, io);
    })

    socket.on("on_user_loggedin", async (data) => {
        OnUserLoggedinController(ctx, data, io,socket);
    })

    socket.on("event_notification", async (data) => {
        EventNotificationController(ctx, data, io,socket);
    })

    socket.on("group_notification", async (data) => {
        GroupNotificationController(ctx, data, io,socket);
    })

    socket.on("page_notification", async (data) => {
        PageNotificationController(ctx, data, io,socket);
    })

    socket.on("user_followers_notification", async (data) => {
        UserFollowersNotificationController(ctx, data, io,socket);
    })

    socket.on("update_new_posts", async (data) => {
        UpdateNewPostsController(ctx, data, io,socket);
    })

    socket.on("decline_call", async (data) => {
        DeclineCallController(ctx, data, io,socket);
    })

    socket.on("user_notification", async (data) => {
        UserNotificationController(ctx, data, io,socket);
    })

    socket.on("register_reaction", async (data) => {
        RegisterReactionController(ctx, data, io,socket);
    })

    socket.on("checkout_notification", async (data) => {
        CheckoutNotificationController(ctx, data, io,socket);
    })

    socket.on("main_notification", async (data) => {
        MainNotificationController(ctx, data, io,socket);
    })

    socket.on("post_notification", async (data) => {
        PostNotificationController(ctx, data, io,socket);
    })

    socket.on("comment_notification", async (data) => {
        CommentNotificationController(ctx, data, io,socket);
    })

    socket.on("reply_notification", async (data) => {
        ReplyNotificationController(ctx, data, io,socket);
    })

    socket.on("on_user_loggedoff", async (data) => {
        OnUserLoggedoffController(ctx, data, io,socket);
    })

    socket.on('seen_messages', async (data) => {
        SeenMessagesController(ctx, data, io,socket);
    })

    socket.on('color_changed', async (data) => {
        ColorChangedController(ctx, data, io,socket);
    })

    socket.on('disconnect', async (reason) => {
        DisconnectController(ctx, reason, io,socket);
    });
}  