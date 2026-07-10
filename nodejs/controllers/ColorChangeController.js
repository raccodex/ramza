const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const ColorChangeController = async (ctx, data, io,socket) => {
  let remainingSameUserSockets = []
  if (ctx.userIdSocket[ctx.userHashUserId[data.from_id]]) {
      remainingSameUserSockets = ctx.userIdSocket[ctx.userHashUserId[data.from_id]].filter(d => d.id != socket.id)
  }
  io.to(data.id).emit('color-change', { color: data.color, sender: data.id, id: ctx.userHashUserId[data.from_id] })
  for (let userSocket of remainingSameUserSockets) {
      userSocket.emit("color-change", { color: data.color, sender: ctx.userHashUserId[data.from_id], id: data.id })
  }
};

module.exports = { ColorChangeController };