const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const MainNotificationController = async (ctx, data, io,socket) => {
  if (!data.user_id || !data.to_id || !data.type) {
      return;
  }
  user_id = ctx.userHashUserId[data.user_id]
  let notification_type = "new_notification";
  if (data.type == 'removed') {
      notification_type = "new_notification_removed";
  }
  await io.to(data.to_id).emit(notification_type, {});
};

module.exports = { MainNotificationController };