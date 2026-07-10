const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const GroupMessageController = async (ctx, data, io,socket,callback) => {
  if ((!data.msg || data.msg.trim() === "") && !data.mediaId) {
      console.log("Message has no text, neither media, skipping")
      return
  }
  if(data.msg){
      data.msg = await funcs.cleanBR(data.msg);
  }

  let groupOwnerId = await ctx.wo_groupchat.findOne({
      attributes: ["user_id"],
      where: {
          group_id: {
              [Op.eq]: data.group_id
          }
      }
  })
  let usersInGroup = await ctx.wo_groupchatusers.findAll({
      attributes: ["user_id"],
      where: {
          group_id: {
              [Op.eq]: data.group_id
          }
      }
  });
  if (!usersInGroup.find(user => user.user_id == ctx.userHashUserId[data.from_id])) {
      console.log("Not in group")
      return
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
  if (ctx.userIdGroupChatOpen[ctx.userHashUserId[data.from_id]] && ctx.userIdGroupChatOpen[ctx.userHashUserId[data.from_id]].filter(d => d == data.group_id) ||
      ctx.userIdExtra[data.to_id] && ctx.userIdExtra[data.to_id].active_message_group_id && +ctx.userIdExtra[data.to_id].active_message_group_id === +ctx.userHashUserId[data.from_id]) {
      var m_sent = await ctx.wo_messages.create({
          from_id: ctx.userHashUserId[data.from_id],
          group_id: data.group_id,
          text: await funcs.Wo_CensoredWords(ctx, data.msg),
          seen: 0,
          time: Math.floor(Date.now() / 1000)
      })
      data.sent_message = m_sent;
  }
  else {
      var m_sent = await ctx.wo_messages.create({
          from_id: ctx.userHashUserId[data.from_id],
          group_id: data.group_id,
          text: await funcs.Wo_CensoredWords(ctx, data.msg),
          seen: 0,
          time: Math.floor(Date.now() / 1000)
      })
      data.sent_message = m_sent;
  }

  let nextId = m_sent.id;
  data.new_message = await ctx.wo_messages.findOne({
      where: {
          id: {
              [Op.eq]: nextId
          }
      }
  });
  data.group_data = await ctx.wo_groupchat.findOne({
      where: {
          group_id: {
              [Op.eq]: data.group_id
          }
      }
  });
  data.group_data.avatar = await funcs.Wo_GetMedia(ctx, data.group_data.avatar);
  if (!data.mediaId) {
      ({ msg,hasHTML } = await funcs.liksMatch(data.msg , hasHTML));
      ({ msg,hasHTML } = await funcs.mentionMatch(msg , hasHTML));
      ({ msg,hasHTML } = await funcs.hashTagsMatch(msg , hasHTML));

      data.msg = msg

      let sendable_message = await funcs.Wo_Markup(ctx, data.msg);

      // if recepient has chat open then send last seen 
      if (ctx.userIdGroupChatOpen[ctx.userHashUserId[data.from_id]] && ctx.userIdGroupChatOpen[ctx.userHashUserId[data.from_id]].filter(d => d == data.group_id) ||
          ctx.userIdExtra[data.to_id] && ctx.userIdExtra[data.to_id].active_message_group_id && +ctx.userIdExtra[data.to_id].active_message_group_id === +ctx.userHashUserId[data.from_id]) {
              if (data.message_reply_id > 0) {
                data = await funcs.canSendReply(ctx, data);
              }
          var m_sent = await ctx.wo_messages.create({
              from_id: ctx.userHashUserId[data.from_id],
              group_id: data.group_id,
              text: await funcs.Wo_CensoredWords(ctx, data.msg),
              seen: 0,
              time: Math.floor(Date.now() / 1000),
              reply_id: parseInt(data.message_reply_id)
          })
          data.sent_message = m_sent;
         // await socketEvents.lastseen(ctx, socket, { seen: Math.floor(Date.now() / 1000) })
      }
      else {
          if (data.message_reply_id > 0) {
            data = await funcs.canSendReply(ctx, data);
          }
          var m_sent = await ctx.wo_messages.create({
              from_id: ctx.userHashUserId[data.from_id],
              group_id: data.group_id,
              text: await funcs.Wo_CensoredWords(ctx, data.msg),
              seen: 0,
              time: Math.floor(Date.now() / 1000),
              reply_id: parseInt(data.message_reply_id)
          })
          data.sent_message = m_sent;
      }

      let responseData = {
          status: 200,
          html: await compiledTemplates.groupListOwnerTrue(ctx, messageOwner, nextId, data, hasHTML, sendable_message, data.color),
          message_page_html: await compiledTemplates.messageListOwnerTrue(ctx, data, messageOwner, nextId, hasHTML, sendable_message, data.color),
          id: data.group_id,
          receiver: ctx.userHashUserId[data.from_id],
          sender: ctx.userHashUserId[data.from_id],
          self: true,
          new_message: (data.new_message && data.new_message !== undefined ? data.new_message : ''),
          group_data: (data.group_data && data.group_data !== undefined ? data.group_data : ''),
          message_id: ((data.sent_message && data.sent_message !== undefined && data.sent_message.id && data.sent_message.id !== undefined ) ? data.sent_message.id : 0),
          time_api: ((data.sent_message && data.sent_message !== undefined && data.sent_message.time && data.sent_message.time !== undefined ) ? data.sent_message.time : 0),
      };

      callback(responseData)
      
      await socketEvents.groupMessage(ctx, io, socket, data, messageOwner, nextId, hasHTML, sendable_message,responseData);
      await socketEvents.groupMessagePage(ctx, io, socket, data, messageOwner, nextId, hasHTML, sendable_message,responseData)


      
  } else {
    let sendable_message = '';
      let responseData = {
          status: 200,
          html: await compiledTemplates.groupListOwnerTrue(ctx, messageOwner, nextId, data, hasHTML, sendable_message, data.color),
          message_page_html: await compiledTemplates.messageListOwnerTrue(ctx, data, messageOwner, nextId, hasHTML, sendable_message, data.color),
          id: data.group_id,
          receiver: ctx.userHashUserId[data.from_id],
          sender: ctx.userHashUserId[data.from_id],
          self: true,
          new_message: (data.new_message && data.new_message !== undefined ? data.new_message : ''),
          group_data: (data.group_data && data.group_data !== undefined ? data.group_data : ''),
          message_id: ((data.sent_message && data.sent_message !== undefined && data.sent_message.id && data.sent_message.id !== undefined ) ? data.sent_message.id : 0),
          time_api: ((data.sent_message && data.sent_message !== undefined && data.sent_message.time && data.sent_message.time !== undefined ) ? data.sent_message.time : 0),
      };
      await socketEvents.groupMessageWithMedia(ctx, io, socket, data, messageOwner, nextId, data.isSticker,responseData);
      await socketEvents.groupMessagePageWithMedia(ctx, io, socket, data, messageOwner, nextId, hasHTML, sendable_message, data.isSticker,responseData);
      await socketEvents.updateMessageGroupsList(ctx, io, messageOwner)
  }

  await socketEvents.emitUserStatus(ctx, socket, ctx.userHashUserId[data.from_id])
  await socketEvents.updateMessageGroupsList(ctx, io, ctx.userHashUserId[data.from_id])
  await socketEvents.emitUserStatus(ctx, socket, data.group_id)
};

module.exports = { GroupMessageController };