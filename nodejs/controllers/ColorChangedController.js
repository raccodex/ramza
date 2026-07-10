const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const ColorChangedController = async (ctx, data, io,socket) => {
  let remainingSameUserSockets = []
  if (ctx.userIdSocket[ctx.userHashUserId[data.from_id]]) {
      remainingSameUserSockets = ctx.userIdSocket[ctx.userHashUserId[data.from_id]].filter(d => d.id != socket.id)
  }

  let fromUser = await ctx.wo_users.findOne({
      where: {
          user_id: {
              [Op.eq]: ctx.userHashUserId[data.from_id]
          }
      }
  })


  let toUser = await ctx.wo_users.findOne({
      where: {
          user_id: {
              [Op.eq]: parseInt(data.to_id)
          }
      }
  })

  const message = new ctx.wo_messages();

  let responseData = {
      status: 200,
      fromUser: fromUser,
      toUser: toUser,
      chatData: await message.getChatData(fromUser.user_id,toUser.user_id)
  }

  for (userSocket of remainingSameUserSockets) {
    await userSocket.emit('color_changed_notify', responseData);
  }
  await io.to(data.to_id).emit('color_changed_notify', responseData);
};

module.exports = { ColorChangedController };