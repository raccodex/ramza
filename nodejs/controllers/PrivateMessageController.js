const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const MessageToOwnerFalse = async (ctx, io, data, responseData) => {
  await io.to(data.to_id).emit('private_message', responseData);
};

const sendNotification = async (ctx, io, data, responseData) => {
  await io.to(data.to_id).emit('notification', responseData);
};

const PrivateMessageController = async (ctx, data, io,socket,callback) => {
  //console.log(data)
        
        
  if ((!data.msg || data.msg.trim() === "") && !data.mediaId && !data.record && !data.lng && !data.lat) {
      console.log("Message has no text, neither media, skipping")
      return
  }

  let remainingSameUserSockets = []
  if (ctx.userIdSocket[ctx.userHashUserId[data.from_id]]) {
      remainingSameUserSockets = ctx.userIdSocket[ctx.userHashUserId[data.from_id]].filter(d => d.id != socket.id)
  }
  if(data.msg){
      data.msg = await funcs.cleanBR(data.msg);
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

  let hasHTML = false;
  let msg;
  var story_id = 0;
  if (data.story_id && data.story_id > 0) {
      story_id = parseInt(data.story_id);
  }
  var lng = 0;
  var lat = 0;
  if (data.lng && data.lat && data.lng !== undefined && data.lat !== undefined) {
      lng = data.lng;
      lat = data.lat;
  }
  if (data.record) {
      hasHTML = true;
      if (data.message_reply_id > 0) {
          data = await funcs.canSendReply(ctx, data);
      }
      let new_message = await ctx.wo_messages.create({
          from_id: ctx.userHashUserId[data.from_id],
          to_id: data.to_id,
          text: "",
          media: data.mediaFilename,
          mediaFileName: data.mediaName,
          seen: 0,
          time: Math.floor(Date.now() / 1000),
          reply_id: parseInt(data.message_reply_id),
          story_id: story_id,
          lng: lng,
          lat: lat,
      })
      data.mediaId = new_message.id;
      data.sent_message = new_message;
      await socket.emit('private_message', {
          messages_html: await compiledTemplates.chatListOwnerTrueWithMedia(ctx, data, fromUser, new_message.id, data.color, data.isSticker),
          id: data.to_id,
          username: ((fromUser && fromUser.first_name !== undefined && fromUser.first_name != '' && fromUser.last_name !== undefined && fromUser.last_name != '') ? fromUser.first_name + ' ' + fromUser.last_name : fromUser.username),
          avatar: ((fromUser && fromUser.avatar !== undefined) ? await funcs.Wo_GetMedia(ctx, fromUser.avatar) : ''),
          receiver: ctx.userHashUserId[data.from_id],
          sender: ctx.userHashUserId[data.from_id],
          status: 200,
          color: data.color,
          isMedia: true,
          isRecord: true,
          mediaLink: funcs.Wo_GetMedia(ctx, data.mediaId),
          time: '<div class="messages-last-sent pull-right time ajax-time" title="' + moment().toISOString() + '">..</div>',
          lng: lng,
          lat: lat,
          message_id: new_message.id,
          time_api: data.sent_message.time,
          messageData: new_message,
          chatData: await data.sent_message.getChatData(),
      });
  }

  ({ msg, hasHTML } = funcs.Wo_Emo(data.msg))
  data.msg = msg
  
  if (!data.mediaId) {
      ({ msg,hasHTML } = await funcs.liksMatch(data.msg , hasHTML));
      ({ msg,hasHTML } = await funcs.mentionMatch(msg , hasHTML));
      ({ msg,hasHTML } = await funcs.hashTagsMatch(msg , hasHTML));

      data.msg = msg

      let sendable_message = await funcs.Wo_Markup(ctx, data.msg);

      
      // if recepient has chat open then send last seen 
      if ((ctx.userIdChatOpen[data.to_id] && ctx.userIdChatOpen[data.to_id].filter(d => d == ctx.userHashUserId[data.from_id]).length) ||
          ctx.userIdExtra[data.to_id] && ctx.userIdExtra[data.to_id].active_message_user_id && +ctx.userIdExtra[data.to_id].active_message_user_id === +ctx.userHashUserId[data.from_id]) {
              if (data.message_reply_id > 0) {
                data = await funcs.canSendReply(ctx, data);
              }
                 
              var m_sent = await ctx.wo_messages.create({
                  from_id: ctx.userHashUserId[data.from_id],
                  to_id: data.to_id,
                  text: await funcs.Wo_CensoredWords(ctx, data.msg),
                  seen: 0,
                  time: Math.floor(Date.now() / 1000),
                  reply_id: parseInt(data.message_reply_id),
                  story_id: story_id,
                  lng: lng,
                  lat: lat,
                  type_two: (data.contact && data.contact.length > 0 ? 'contact' : ''),
              })
              data.sent_message = m_sent;
              //await socketEvents.lastseen(ctx, socket, { seen: Math.floor(Date.now() / 1000) })

      } 
      else {
          if (data.message_reply_id > 0) {
              data = await funcs.canSendReply(ctx, data);
          }
          var m_sent = await ctx.wo_messages.create({
              from_id: ctx.userHashUserId[data.from_id],
              to_id: data.to_id,
              text: await funcs.Wo_CensoredWords(ctx, data.msg),
              seen: 0,
              time: Math.floor(Date.now() / 1000),
              reply_id: parseInt(data.message_reply_id),
              story_id: story_id,
              lng: lng,
              lat: lat,
          })
          data.sent_message = m_sent;
      }

      data.sent_message_id = data.sent_message.id;


      let responseData = {
          messages_html: await compiledTemplates.chatListOwnerTrue(ctx, data, fromUser, m_sent.id, hasHTML, sendable_message, data.color),
          message_page_html: await compiledTemplates.messageListOwnerTrue(ctx, data, fromUser, m_sent.id, hasHTML, sendable_message, data.color),
          id: data.to_id,
          username: ((fromUser && fromUser.first_name !== undefined && fromUser.first_name != '' && fromUser.last_name !== undefined && fromUser.last_name != '') ? fromUser.first_name + ' ' + fromUser.last_name : fromUser.username),
          avatar: ((fromUser && fromUser.avatar !== undefined) ? await funcs.Wo_GetMedia(ctx, fromUser.avatar) : ''),
          status: 200,
          receiver: ctx.userHashUserId[data.from_id],
          sender: ctx.userHashUserId[data.from_id],
          color: data.color,
          self: true,
          message: data.msg,
          message_html: sendable_message,
          time: '<div class="messages-last-sent pull-right time ajax-time" title="' + moment().toISOString() + '">..</div>',
          isMedia: false,
          isRecord: false,
          lng: lng,
          lat: lat,
          message_id: data.sent_message_id,
          time_api: data.sent_message.time,
          messageData: data.sent_message,
          chatData: await data.sent_message.getChatData(),
      }


      callback(responseData)
      // send same message to all tabs
      for (userSocket of remainingSameUserSockets) {
        responseData.messages_html = await compiledTemplates.chatListOwnerTrue(ctx, data, fromUser, m_sent.id, hasHTML, sendable_message, data.color);
        await userSocket.emit('private_message', responseData);
        responseData.messages_html = await compiledTemplates.messageListOwnerTrue(ctx, data, fromUser, m_sent.id, hasHTML, sendable_message, data.color);
        await userSocket.emit('private_message_page', responseData);
      }

      responseData.messages_html = await compiledTemplates.chatListOwnerFalse(ctx, data, fromUser, m_sent.id, hasHTML, sendable_message);
      responseData.message_page_html = await compiledTemplates.messageListOwnerFalse(ctx, data, sendable_message, fromUser, hasHTML, sendable_message);
      responseData.id = ctx.userHashUserId[data.from_id];
      await MessageToOwnerFalse(ctx, io, data, responseData)
      await sendNotification(ctx, io, data, responseData);
      
      await funcs.updateOrCreate(ctx.wo_userschat, {
          user_id: ctx.userHashUserId[data.from_id],
          conversation_user_id: data.to_id,
      }, {
          time: Math.floor(Date.now() / 1000),
          user_id: ctx.userHashUserId[data.from_id],
          conversation_user_id: data.to_id,
          color: data.color
      })
      await funcs.updateOrCreate(ctx.wo_userschat, {
          conversation_user_id: ctx.userHashUserId[data.from_id],
          user_id: data.to_id,
      }, {
          time: Math.floor(Date.now() / 1000),
          conversation_user_id: ctx.userHashUserId[data.from_id],
          user_id: data.to_id,
          color: data.color
      })
  }
  else {
      let sendable_message = data.msg;
      m_sent = await ctx.wo_messages.findOne({
          where: {
              id: data.mediaId
          }
      });
      data.sent_message = m_sent;
      data.sent_message_id = m_sent.id;

      let responseData = {
          messages_html: await compiledTemplates.chatListOwnerTrueWithMedia(ctx, data, fromUser, m_sent.id, hasHTML, data.color, data.isSticker),
          message_page_html: await compiledTemplates.messageListOwnerFalseWithMedia(ctx, data, m_sent.id, fromUser, data.isSticker),
          id: data.to_id,
          username: ((fromUser && fromUser.first_name !== undefined && fromUser.first_name != '' && fromUser.last_name !== undefined && fromUser.last_name != '') ? fromUser.first_name + ' ' + fromUser.last_name : fromUser.username),
          avatar: ((fromUser && fromUser.avatar !== undefined) ? await funcs.Wo_GetMedia(ctx, fromUser.avatar) : ''),
          receiver: ctx.userHashUserId[data.from_id],
          sender: ctx.userHashUserId[data.from_id],
          status: 200,
          color: data.color,
          message: data.msg,
          time: '<div class="messages-last-sent pull-right time ajax-time" title="' + moment().toISOString() + '">..</div>',
          mediaLink: (m_sent.stickers.length > 0 ? m_sent.stickers : funcs.Wo_GetMedia(ctx, m_sent.media)),
          isMedia: true,
          isRecord: true,
          lng: lng,
          lat: lat,
          message_id: (data.sent_message_id && data.sent_message_id !== undefined) ? data.sent_message_id : 0 ,
          time_api: (data.sent_message && data.sent_message !== undefined && data.sent_message && data.sent_message.time !== undefined) ? data.sent_message.time : 0,
          messageData: data.sent_message,
          chatData: await data.sent_message.getChatData(),
      };

      for (userSocket of remainingSameUserSockets) {
          responseData.message_page_html = await compiledTemplates.chatListOwnerTrueWithMedia(ctx, data, fromUser, m_sent.id, hasHTML, data.color, data.isSticker);
          await userSocket.emit('private_message', responseData);
          responseData.message_page_html = await compiledTemplates.messageListOwnerTrueWithMedia(ctx, data, fromUser, m_sent.id, hasHTML, data.color, data.isSticker);
          await userSocket.emit('private_message_page', responseData);
      }
      responseData.messages_html = await compiledTemplates.chatListOwnerFalseWithMedia(ctx, data, fromUser, m_sent.id, hasHTML, data.isSticker),
      responseData.id = ctx.userHashUserId[data.from_id];
      await MessageToOwnerFalse(ctx, io, data, responseData)
      await sendNotification(ctx, io, data, responseData);
  }




  
  
  
};



module.exports = { PrivateMessageController };