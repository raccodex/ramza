const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const PostNotificationController = async (ctx, data, io,socket) => {
  let user_id = ctx.userHashUserId[data.user_id]
  if (!data.post_id) {
      return;
  }
  let post_id = data.post_id;
  let postData = await ctx.wo_posts.findOne({
      attributes: ["user_id"],
      where: {
          id: post_id
      },
      raw: true
  });
  let notification_type = "new_notification";
  if (data.type == 'removed') {
      notification_type = "new_notification_removed";
  }
  if (postData.user_id !== user_id) {
      await io.to(postData.user_id).emit(notification_type, { post_id: post_id });
  }
};

module.exports = { PostNotificationController };