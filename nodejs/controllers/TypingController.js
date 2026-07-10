const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const TypingController = async (ctx, data, io,socket) => {
  let fromUser = await ctx.wo_users.findOne({
      where: {
          user_id: {
              [Op.eq]: ctx.userHashUserId[data.user_id]
          }
      }
  })
  if (!fromUser) {
      console.log("Skipping no from_user")
      return
  }
  if (ctx.userIdExtra[ctx.userHashUserId[data.user_id]]) {
      if (ctx.userIdExtra[ctx.userHashUserId[data.user_id]].typingTimeout) {
          clearTimeout(ctx.userIdExtra[ctx.userHashUserId[data.user_id]].typingTimeout)
      }
      ctx.userIdExtra[ctx.userHashUserId[data.user_id]].typingTimeout = setTimeout(async () => {
          await socketEvents.typingDone(ctx, io, data, ctx.userHashUserId[data.user_id])
      }, 2000)
  }
  else {
      ctx.userIdExtra[ctx.userHashUserId[data.user_id]] = {
          typingTimeout: setTimeout(async () => {
              await socketEvents.typingDone(ctx, io, data, ctx.userHashUserId[data.user_id])
          }, 2000)
      }
  }
  // await funcs.Wo_RegisterTyping(data.user_id, data.recipient_id, 1)
  await socketEvents.typing(ctx, io, fromUser.avatar, data.recipient_id, ctx.userHashUserId[data.user_id])
};

module.exports = { TypingController };