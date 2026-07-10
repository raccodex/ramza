const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const ActiveMessageUserChangeController = async (ctx, data, io,socket) => {
  if (data.group) {
      if (ctx.userIdExtra[ctx.userHashUserId[data.from_id]]) {
          ctx.userIdExtra[ctx.userHashUserId[data.from_id]].active_message_group_id = data.group_id;
          // await socketEvents.emitUserStatus(ctx, io, ctx.userHashUserId[data.from_id])
          // await socketEvents.updateMessageUsersList(ctx, io, ctx.userHashUserId[data.from_id])
          await socketEvents.updateMessageGroupsList(ctx, io, ctx.userHashUserId[data.from_id])
          return
      }
      ctx.userIdExtra[ctx.userHashUserId[data.from_id]] = { active_message_group_id: data.group_id };
  }
  else {
      if (ctx.userIdExtra[ctx.userHashUserId[data.from_id]]) {
          ctx.userIdExtra[ctx.userHashUserId[data.from_id]].active_message_user_id = data.user_id;
          // await socketEvents.emitUserStatus(ctx, io, ctx.userHashUserId[data.from_id])
          // await socketEvents.updateMessageUsersList(ctx, io, ctx.userHashUserId[data.from_id])
          await socketEvents.updateMessageGroupsList(ctx, io, ctx.userHashUserId[data.from_id])
          return
      }
      ctx.userIdExtra[ctx.userHashUserId[data.from_id]] = { active_message_user_id: data.user_id };
  }
  // await socketEvents.emitUserStatus(ctx, io, ctx.userHashUserId[data.from_id])
  // await socket.emitUserStatus(ctx, io, data.user_id)
  // await socketEvents.updateMessageUsersList(ctx, io, ctx.userHashUserId[data.from_id])
  await socketEvents.updateMessageGroupsList(ctx, io, ctx.userHashUserId[data.from_id])
};

module.exports = { ActiveMessageUserChangeController };