const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const PageNotificationController = async (ctx, data, io,socket) => {
  let user_id = ctx.userHashUserId[data.user_id]
  if (!data.to_id) {
      return;
  }
  let to_id = data.to_id;
  let pageData = await ctx.wo_pages.findOne({
      attributes: ["user_id"],
      where: {
          page_id: parseInt(to_id)
      },
      raw: true
  });
  if (pageData.user_id > 0) {
      let notification_type = "new_notification";
      if (data.type == 'removed') {
          notification_type = "new_notification_removed";
      }
      if (pageData.user_id !== user_id) {
          await io.to(pageData.user_id).emit(notification_type, { user_id: user_id });
      }
  }
};

module.exports = { PageNotificationController };