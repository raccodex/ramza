const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const CloseChatController = async (ctx, data, io,socket) => {
  if (data.group) {
      if (ctx.userIdGroupChatOpen[ctx.userHashUserId[data.user_id]] && ctx.userIdGroupChatOpen[ctx.userHashUserId[data.user_id]].length) {
          ctx.userIdGroupChatOpen[ctx.userHashUserId[data.user_id]] = ctx.userIdGroupChatOpen[ctx.userHashUserId[data.user_id]].filter(d => d != data.recipient_id)
      }
  }
  else if (ctx.userIdChatOpen[ctx.userHashUserId[data.user_id]] && ctx.userIdChatOpen[ctx.userHashUserId[data.user_id]].length) {
      ctx.userIdChatOpen[ctx.userHashUserId[data.user_id]] = ctx.userIdChatOpen[ctx.userHashUserId[data.user_id]].filter(d => d != data.recipient_id);
      //ctx.userIdChatOpen[ctx.userHashUserId[data.user_id]] = 0;
  }
};

module.exports = { CloseChatController };