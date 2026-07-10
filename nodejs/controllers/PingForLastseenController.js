const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const PingForLastseenController = async (ctx, data, io) => {
  if (ctx.userHashUserId[data.user_id]) {
      let userlastseen_status = await ctx.wo_users.findOne({
          attributes: [
              "status"
          ],
          where: {
              user_id: ctx.userHashUserId[data.user_id]
          }
      })
      if (userlastseen_status.status == 0) {
          await funcs.Wo_LastSeen(ctx, ctx.userHashUserId[data.user_id])
      }
  }
};

module.exports = { PingForLastseenController };