const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const MuteController = async (ctx, data, io,socket,callback) => {
  if(!data.chat_id || !data.type || !data.user_id){
      console.log("chat_id , type , user_id can not be empty")
      return;
  }
  if(data.type != 'user' && data.type != 'page' && data.type != 'group'){
      console.log("wrong type")
      return;
  }
  if(!data.notify && data.call_chat && data.archive && data.pin){
      console.log("empty data")
      return;
  }
  let info = await ctx.wo_mute.findOne({
      where: {
          user_id: {
              [Op.eq]: ctx.userHashUserId[data.user_id]
          },
          type: {
              [Op.eq]: data.type
          },
          chat_id: {
              [Op.eq]: data.chat_id
          }
      }
  })
  var update_object = {};
  if(data.notify && (data.notify == 'no' || data.notify == 'yes')){
      update_object.notify = data.notify;
  }
  if(data.call_chat && (data.call_chat == 'no' || data.call_chat == 'yes')){
      update_object.call_chat = data.call_chat;
  }
  if(data.archive && (data.archive == 'no' || data.archive == 'yes')){
      update_object.archive = data.archive;
  }
  if(data.pin && (data.pin == 'no' || data.pin == 'yes')){
      update_object.pin = data.pin;
  }
  update_object.chat_id = data.chat_id;

  if(info && info.id){
      await ctx.wo_mute.update(update_object,
      {
          where: {
              id: info.id
          }
      })

  }
  else{
      update_object.user_id = ctx.userHashUserId[data.user_id];
      update_object.type = data.type;
      update_object.time = Math.floor(Date.now() / 1000);
      await ctx.wo_mute.create(update_object)

  }
  await socket.emit('mute', update_object);
};

module.exports = { MuteController };