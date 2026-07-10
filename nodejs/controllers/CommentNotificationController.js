const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const CommentNotificationController = async (ctx, data, io,socket) => {
  let user_id = ctx.userHashUserId[data.user_id]
  if (!data.comment_id) {
      return;
  }
  let comment_id = data.comment_id;
  let commentData = await ctx.wo_comments.findOne({
      attributes: ["user_id"],
      where: {
          id: comment_id
      },
      raw: true
  });
  let notification_type = "new_notification";

  if (data.type == 'removed') {
      notification_type = "new_notification_removed";
  }
  if (typeof data.for !== 'undefined') {
      let replyData = await ctx.wo_comment_replies.findAll({
          attributes: ["user_id"],
          where: {
              comment_id: comment_id,
          },
          raw: true
      })
      let sentUsers = [];
      if (replyData.length > 0) {
          for (let userReply of replyData) {
              if (userReply.user_id > 0) {
                  if (userReply.user_id !== user_id && !sentUsers.includes(userReply.user_id)) {
                      await io.to(userReply.user_id).emit("new_notification", { comment_id: comment_id });
                      await io.to(userReply.user_id).emit("load_comment_replies", { comment_id: comment_id });
                      sentUsers.push(userReply.user_id);
                  }
              }
          }
      } 
      if (commentData.user_id !== user_id && !sentUsers.includes(commentData.user_id)) {
          await io.to(commentData.user_id).emit(notification_type, { comment_id: comment_id });
      }
  } else {
      if (commentData.user_id !== user_id) {
          await io.to(commentData.user_id).emit(notification_type, { comment_id: comment_id });
      }
  }
};

module.exports = { CommentNotificationController };