const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const GetReactionController = async (ctx, data, io,socket) => {
  if(!data.id || !data.type || !data.user_id){
      console.log("id , type , user_id can not be empty")
      return;
  }
  var result = await funcs.Wo_GetPostReactionsTypes(ctx, data.id,data.type,ctx.userHashUserId[data.user_id]);
  await socket.emit('get_reaction', Object.assign({}, result));
};

module.exports = { GetReactionController };