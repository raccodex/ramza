const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const UserNotificationController = async (ctx, data, io,socket) => {
  let user_id = ctx.userHashUserId[data.user_id]
  if (!data.to_id) {
      return;
  }
  let to_id = data.to_id;
  let userData = await ctx.wo_users.findOne({
      attributes: ["user_id"],
      where: {
          user_id: parseInt(to_id)
      },
      raw: true
  });
  if (userData.user_id > 0) {
      let notification_type = "new_notification";
      if (data.type == 'removed') {
          notification_type = "new_notification_removed";
      } else if (data.type == 'request') {
           notification_type = "new_request";
      } else if (data.type == 'request_removed') {
          notification_type = "new_request_removed";
      } else if (data.type == 'create_video') {
          notification_type = "new_video_call";
      }
      if (userData.user_id !== user_id) {
          await io.to(userData.user_id).emit(notification_type, { notification_data: data });
      }
  }
};

module.exports = { UserNotificationController };