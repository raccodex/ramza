const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const PageMessageController = async (ctx, data, io,socket,callback) => {
  if ((!data.msg || data.msg.trim() === "") && !data.mediaId) {
      console.log("Message has no text, neither media, skipping")
      return
  }

  if(data.msg){
      data.msg = await funcs.sanitizeJS(data.msg);
      data.msg = data.msg.replace("\r\n", " <br>");
      data.msg = data.msg.replace("\n\r", " <br>");
      data.msg = data.msg.replace("\r", " <br>");
      data.msg = data.msg.replace("\n", " <br>");
  }

  let page_data = await ctx.wo_pages.findOne({
      where: {
          page_id: {
              [Op.eq]: data.page_id
          }
      }
  });
  var to_id = page_data.user_id;
  if (page_data.user_id == ctx.userHashUserId[data.from_id]) {
      if (page_data.user_id == data.to_id) {
          to_id = ctx.userHashUserId[data.from_id];
      }
      else{
          to_id = data.to_id;
      }
  }

  let messageOwner = await ctx.wo_users.findOne({
      where: {
          user_id: {
              [Op.eq]: ctx.userHashUserId[data.from_id]
          }
      }
  });
  let hasHTML = false;
  let msg;
  ({ msg, hasHTML } = funcs.Wo_Emo(data.msg))
  data.msg = msg
  // if recepient has chat open then send last seen 
  if (data.message_reply_id > 0) {
      data = await funcs.canSendReply(ctx, data);
  }
  if ((ctx.userIdChatOpen[data.to_id] && ctx.userIdChatOpen[data.to_id].filter(d => d == ctx.userHashUserId[data.from_id]).length) ||
          ctx.userIdExtra[data.to_id] && ctx.userIdExtra[data.to_id].active_message_user_id && +ctx.userIdExtra[data.to_id].active_message_user_id === +ctx.userHashUserId[data.from_id]) {
      var m_sent = await ctx.wo_messages.create({
          from_id: ctx.userHashUserId[data.from_id],
          page_id: data.page_id,
          to_id: to_id,
          text: await funcs.Wo_CensoredWords(ctx, data.msg),
          seen: 0,
          time: Math.floor(Date.now() / 1000),
          reply_id: parseInt(data.message_reply_id),
      })
      data.sent_message = m_sent;
  }
  else {
      var m_sent = await ctx.wo_messages.create({
          from_id: ctx.userHashUserId[data.from_id],
          page_id: data.page_id,
          to_id: to_id,
          text: await funcs.Wo_CensoredWords(ctx, data.msg),
          seen: 0,
          time: Math.floor(Date.now() / 1000),
          reply_id: parseInt(data.message_reply_id),
      })
      data.sent_message = m_sent;
  }

  let nextId = m_sent.id;
  page_data.avatar = await funcs.Wo_GetMedia(ctx, page_data.avatar);
  page_data.cover = await funcs.Wo_GetMedia(ctx, page_data.cover);
  let new_message = await ctx.wo_messages.findOne({
      where: {
          id: {
              [Op.eq]: nextId
          }
      }
  });
  if (!data.mediaId) {
    ({ msg,hasHTML } = await funcs.liksMatch(data.msg , hasHTML));
    ({ msg,hasHTML } = await funcs.mentionMatch(msg , hasHTML));
    ({ msg,hasHTML } = await funcs.hashTagsMatch(msg , hasHTML));

    data.msg = msg

      let sendable_message = await funcs.Wo_Markup(ctx, data.msg);
      var lng = 0;
      var lat = 0;
      if (data.lng && data.lat && data.lng !== undefined && data.lat !== undefined) {
          lng = data.lng;
          lat = data.lat;
      }

      let responseData = {
          status: 200,
          message_id: data.sent_message.id,
          time_api: data.sent_message.time,
          message: sendable_message,
          lng: lng,
          lat: lat,
          page_data:page_data,
          new_message:new_message,
          time_api: ((data.sent_message && data.sent_message !== undefined && data.sent_message.time && data.sent_message.time !== undefined ) ? data.sent_message.time : 0),
      };
      
      callback(responseData)
      await io.to(to_id).emit('page_message', responseData);
  } else {
      await io.to(to_id).emit('page_message', responseData);
  }
};

module.exports = { PageMessageController };