const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const ReplyNotificationController = async (ctx, data, io,socket) => {
  let user_id = ctx.userHashUserId[data.user_id]
  if (!data.reply_id) {
      return;
  }
  let reply_id = data.reply_id;
  let replyData = await ctx.wo_comment_replies.findOne({
      attributes: ["user_id"],
      where: {
          id: reply_id
      },
      raw: true
  });
  let notification_type = "new_notification";
  if (data.type == 'removed') {
      notification_type = "new_notification_removed";
  }
  if (replyData.user_id !== user_id) {
      await io.to(replyData.user_id).emit(notification_type, { reply_id: reply_id });
  }
};

module.exports = { ReplyNotificationController };