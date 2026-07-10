const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const CheckoutNotificationController = async (ctx, data, io,socket) => {
  if (!data.user_id || !data.users || !data.type) {
      return;
  }
  user_id = ctx.userHashUserId[data.user_id]
  let notification_type = "new_notification";
  if (data.type == 'removed') {
      notification_type = "new_notification_removed";
  }
  for (let user of data.users) {
      await io.to(user).emit(notification_type, {});
  }
};

module.exports = { CheckoutNotificationController };